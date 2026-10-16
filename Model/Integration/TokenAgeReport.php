<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Integration;

use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\Status;

/**
 * Judges integration access tokens by age, from rows already read.
 *
 * A Magento integration token never expires on its own. Every system it was
 * pasted into - a Claude connector, a CI job, an ERP - keeps it working for
 * as long as nobody presses Reauthorize, so the age of the token is how long
 * a leak would have gone unnoticed. Ninety days is the usual rotation policy;
 * twice that is overdue rather than due.
 *
 * Kept apart from the collector, which only runs the query, so the judgement
 * can be tested with literal rows and no database.
 */
class TokenAgeReport
{
    public const WARN_DAYS = 90;
    public const ERROR_DAYS = 180;

    /** Values of integration.status. */
    private const STATUS_ACTIVE = 1;

    private const SECTION = 'Access Tokens';

    private const REAUTHORIZE = 'Reauthorize it under System > Extensions > Integrations to issue a new token, '
        . 'then update every system that holds the old one.';

    private Formatter $formatter;

    /**
     * @param Formatter $formatter
     */
    public function __construct(Formatter $formatter)
    {
        $this->formatter = $formatter;
    }

    /**
     * @param Result $result
     * @param array<int, array<string, mixed>> $rows integration_id, name, status, token_created_at
     *     (UTC "Y-m-d H:i:s", null when there is no live token), full_access, mcp_access
     * @param int $now Unix time
     * @return void
     */
    public function build(Result $result, array $rows, int $now): void
    {
        $result->setWide(self::SECTION);

        if ($rows === []) {
            $result->setSummary('No integrations. Nothing holds a long-lived API token.');
            $result->add(self::SECTION, 'Integrations', '0', Status::OK);

            return;
        }

        $live = 0;
        $stale = 0;
        $oldest = null;

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '') !== '' ? (string) $row['name'] : '#' . (int) $row['integration_id'];
            $age = $this->ageInDays($row['token_created_at'] ?? null, $now);

            if ($age === null) {
                $result->add(self::SECTION, $name, 'no live token', Status::INFO, $this->access($row));
                continue;
            }

            $live++;
            $oldest = $oldest === null ? $age : max($oldest, $age);
            $status = $this->status($age);
            if ($status !== Status::OK) {
                $stale++;
            }

            $hint = trim(sprintf(
                'Issued %s. %s%s',
                gmdate('Y-m-d', $now - $age * 86400),
                $this->access($row),
                $status === Status::OK ? '' : ' ' . self::REAUTHORIZE
            ));
            if ((int) ($row['status'] ?? 0) !== self::STATUS_ACTIVE) {
                $hint .= ' The integration is not active; delete it if it is no longer used.';
            }

            $result->add(
                self::SECTION,
                $name,
                sprintf('%s days', $this->formatter->number($age)),
                $status,
                $hint
            );
        }

        $result->setSummary($live === 0
            ? sprintf('%d integrations, none holding a live token.', count($rows))
            : sprintf(
                '%d live tokens, oldest %d days; %d past the %d-day rotation.',
                $live,
                (int) $oldest,
                $stale,
                self::WARN_DAYS
            ));
    }

    /**
     * @param int $days
     * @return string
     */
    public function status(int $days): string
    {
        if ($days >= self::ERROR_DAYS) {
            return Status::ERROR;
        }

        return $days >= self::WARN_DAYS ? Status::WARN : Status::OK;
    }

    /**
     * Whole days since the token was issued, or null when there is none.
     *
     * @param mixed $createdAt
     * @param int $now
     * @return int|null
     */
    private function ageInDays($createdAt, int $now): ?int
    {
        if (!is_string($createdAt) || $createdAt === '') {
            return null;
        }

        $issued = strtotime($createdAt . ' UTC');
        if ($issued === false) {
            return null;
        }

        return max(0, intdiv($now - $issued, 86400));
    }

    /**
     * What the token can reach, in the terms an admin acts on.
     *
     * @param array<string, mixed> $row
     * @return string
     */
    private function access(array $row): string
    {
        $parts = [];
        if (!empty($row['full_access'])) {
            $parts[] = 'Full admin access (every ACL resource).';
        }
        if (!empty($row['mcp_access'])) {
            $parts[] = 'Can use the AI MCP server.';
        }

        return implode(' ', $parts);
    }
}
