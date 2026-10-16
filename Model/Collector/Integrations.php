<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Collector;

use Magenx\Platform\Model\Integration\TokenAgeReport;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\ResultFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * How old each integration's access token is.
 *
 * One query over Magento's own tables: the integration, its live access token
 * in oauth_token, and whether its role grants every resource or the AI MCP
 * server. Nothing is decrypted and no token value is read - only when it was
 * issued. The judgement is {@see TokenAgeReport}'s.
 */
class Integrations implements CollectorInterface
{
    /** oauth_token.user_type and authorization_role.user_type for an integration. */
    private const USER_TYPE_INTEGRATION = 1;

    private ResourceConnection $resource;

    private ResultFactory $resultFactory;

    private TokenAgeReport $report;

    /**
     * @param ResourceConnection $resource
     * @param ResultFactory $resultFactory
     * @param TokenAgeReport $report
     */
    public function __construct(
        ResourceConnection $resource,
        ResultFactory $resultFactory,
        TokenAgeReport $report
    ) {
        $this->resource = $resource;
        $this->resultFactory = $resultFactory;
        $this->report = $report;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Integrations';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        /** @var Result $result */
        $result = $this->resultFactory->create();
        $this->report->build($result, $this->rows(), time());

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        $connection = $this->resource->getConnection();
        $type = self::USER_TYPE_INTEGRATION;

        // The oldest live access token is the one that matters: Reauthorize
        // replaces the token, so more than one live token means an old one
        // was left behind and is still valid.
        $select = $connection->select()
            ->from(
                ['i' => $this->resource->getTableName('integration')],
                ['integration_id', 'name', 'status']
            )
            ->joinLeft(
                ['t' => $this->resource->getTableName('oauth_token')],
                sprintf(
                    "t.consumer_id = i.consumer_id AND t.type = 'access' AND t.revoked = 0 AND t.user_type = %d",
                    $type
                ),
                ['token_created_at' => new Expression('MIN(t.created_at)')]
            )
            ->columns([
                'full_access' => $this->grants(['Magento_Backend::all']),
                'mcp_access' => $this->grants(['Magento_Backend::all', 'Magenx_AiMcp::server']),
            ])
            ->group('i.integration_id')
            ->order('i.name');

        return $connection->fetchAll($select);
    }

    /**
     * Whether the integration's own role allows any of these resources.
     *
     * @param string[] $resources
     * @return Expression
     */
    private function grants(array $resources): Expression
    {
        $connection = $this->resource->getConnection();

        return new Expression(sprintf(
            'EXISTS (SELECT 1 FROM %s AS ar INNER JOIN %s AS ru ON ru.role_id = ar.role_id'
            . ' WHERE ar.user_type = %d AND ar.user_id = i.integration_id'
            . " AND ru.permission = 'allow' AND ru.resource_id IN (%s))",
            $connection->quoteIdentifier($this->resource->getTableName('authorization_role')),
            $connection->quoteIdentifier($this->resource->getTableName('authorization_rule')),
            self::USER_TYPE_INTEGRATION,
            $connection->quote($resources)
        ));
    }
}
