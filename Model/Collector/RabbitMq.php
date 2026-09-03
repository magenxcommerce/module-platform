<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Collector;

use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Http\JsonFetcher;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\ResultFactory;
use Magenx\Platform\Model\Metric\Status;
use Magento\Framework\App\DeploymentConfig;

/**
 * RabbitMQ health, via the HTTP management API.
 *
 * AMQP itself reports nothing about the broker, so the numbers have to come
 * from the management plugin. Its address is derived from the amqp host in
 * app/etc/env.php on the conventional port unless an admin overrides it, and
 * the credentials are the amqp ones — there is nothing extra to configure and
 * nothing extra to leak.
 *
 * The line that matters most on this tab is queue depth against consumer count:
 * a backlog with zero consumers is a stopped queue:consumers:start, and it is
 * invisible everywhere else in the admin until customers start complaining that
 * orders never confirm.
 */
class RabbitMq implements CollectorInterface
{
    private const DEFAULT_MANAGEMENT_PORT = 15672;

    private const RESOURCE_WARN_PCT = 80.0;
    private const RESOURCE_ERROR_PCT = 95.0;

    /** Queues deeper than this with no consumer are called out individually. */
    private const BACKLOG_WARN = 100;

    private const MAX_QUEUES = 25;

    private DeploymentConfig $deploymentConfig;

    private JsonFetcher $fetcher;

    private Config $config;

    private ResultFactory $resultFactory;

    private Formatter $formatter;

    private Status $status;

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param JsonFetcher $fetcher
     * @param Config $config
     * @param ResultFactory $resultFactory
     * @param Formatter $formatter
     * @param Status $status
     */
    public function __construct(
        DeploymentConfig $deploymentConfig,
        JsonFetcher $fetcher,
        Config $config,
        ResultFactory $resultFactory,
        Formatter $formatter,
        Status $status
    ) {
        $this->deploymentConfig = $deploymentConfig;
        $this->fetcher = $fetcher;
        $this->config = $config;
        $this->resultFactory = $resultFactory;
        $this->formatter = $formatter;
        $this->status = $status;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'RabbitMQ';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        /** @var Result $result */
        $result = $this->resultFactory->create();

        $amqp = $this->deploymentConfig->get('queue/amqp');
        if (!is_array($amqp) || ($amqp['host'] ?? '') === '') {
            return $result->setStatus(Status::UNAVAILABLE)
                ->setSummary('No amqp connection is configured in app/etc/env.php.');
        }

        $vhost = (string) ($amqp['virtualhost'] ?? '/');
        $base = $this->resolveManagementUrl((string) $amqp['host']);

        $result->add('Broker', 'AMQP Endpoint', sprintf('%s:%s', $amqp['host'], $amqp['port'] ?? 5672));
        $result->add('Broker', 'Virtual Host', $vhost);
        // Redacted: an admin may have configured the management URL with inline
        // credentials, and this row is rendered on the tab.
        $result->add('Broker', 'Management API', $this->fetcher->redact($base));

        $overview = $this->getJson($base . '/api/overview', $amqp);
        if ($overview === null) {
            return $result->setStatus(Status::UNAVAILABLE)->setSummary(
                sprintf(
                    'The management API did not answer (%s). Enable it with '
                    . '"rabbitmq-plugins enable rabbitmq_management", or point the module at it in '
                    . 'Stores > Configuration > Magenx > Platform Overview.',
                    $this->fetcher->getLastError()
                )
            );
        }

        $result->setSummary(
            sprintf('RabbitMQ %s on %s', $overview['rabbitmq_version'] ?? '?', $overview['cluster_name'] ?? '?')
        );
        $this->addOverviewRows($result, $overview);
        $this->addNodeRows($result, $base, $amqp);
        $this->addQueueRows($result, $base, $vhost, $amqp);

        return $result;
    }

    /**
     * @param Result $result
     * @param array $overview
     * @return void
     */
    private function addOverviewRows(Result $result, array $overview): void
    {
        $section = 'Broker';
        $counts = $overview['queue_totals'] ?? [];

        $result->add($section, 'Version', (string) ($overview['rabbitmq_version'] ?? 'n/a'));
        $result->add($section, 'Erlang', (string) ($overview['erlang_version'] ?? 'n/a'));
        $result->add($section, 'Messages Ready', $this->formatter->number($counts['messages_ready'] ?? 0));
        $result->add(
            $section,
            'Messages Unacknowledged',
            $this->formatter->number($counts['messages_unacknowledged'] ?? 0),
            Status::INFO,
            'Delivered to a consumer that has not confirmed yet. A number that never falls means a wedged consumer.'
        );

        $rates = $overview['message_stats'] ?? [];
        $result->add(
            $section,
            'Publish / Deliver Rate',
            sprintf(
                '%.1f/s in, %.1f/s out',
                (float) ($rates['publish_details']['rate'] ?? 0),
                (float) ($rates['deliver_get_details']['rate'] ?? 0)
            )
        );
    }

    /**
     * @param Result $result
     * @param string $base
     * @param array $amqp
     * @return void
     */
    private function addNodeRows(Result $result, string $base, array $amqp): void
    {
        $nodes = $this->getJson($base . '/api/nodes', $amqp);
        if (!is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $section = 'Node ' . ($node['name'] ?? '?');

            $result->add(
                $section,
                'Running',
                !empty($node['running']) ? 'Yes' : 'No',
                !empty($node['running']) ? Status::OK : Status::ERROR
            );

            $alarms = [];
            if (!empty($node['mem_alarm'])) {
                $alarms[] = 'memory';
            }
            if (!empty($node['disk_free_alarm'])) {
                $alarms[] = 'disk';
            }
            $result->add(
                $section,
                'Alarms',
                $alarms === [] ? 'None' : implode(', ', $alarms),
                $alarms === [] ? Status::OK : Status::ERROR,
                'A raised alarm blocks every publisher on the node until it clears.'
            );

            $memUsed = (float) ($node['mem_used'] ?? 0);
            $memLimit = (float) ($node['mem_limit'] ?? 0);
            if ($memLimit > 0) {
                $result->add(
                    $section,
                    'Memory',
                    $this->formatter->bytesOf($memUsed, $memLimit),
                    $this->status->forCeiling(
                        $this->formatter->ratio($memUsed, $memLimit),
                        self::RESOURCE_WARN_PCT,
                        self::RESOURCE_ERROR_PCT
                    )
                );
            }

            $diskFree = (float) ($node['disk_free'] ?? 0);
            $diskLimit = (float) ($node['disk_free_limit'] ?? 0);
            if ($diskLimit > 0) {
                $result->add(
                    $section,
                    'Disk Free',
                    sprintf('%s (limit %s)', $this->formatter->bytes($diskFree), $this->formatter->bytes($diskLimit)),
                    $diskFree <= $diskLimit ? Status::ERROR : Status::OK,
                    'Falling to the limit raises the disk alarm and stops all publishing.'
                );
            }

            $fdUsed = (float) ($node['fd_used'] ?? 0);
            $fdTotal = (float) ($node['fd_total'] ?? 0);
            if ($fdTotal > 0) {
                $result->add(
                    $section,
                    'File Descriptors',
                    sprintf('%s / %s', $this->formatter->number($fdUsed), $this->formatter->number($fdTotal)),
                    $this->status->forCeiling(
                        $this->formatter->ratio($fdUsed, $fdTotal),
                        self::RESOURCE_WARN_PCT,
                        self::RESOURCE_ERROR_PCT
                    )
                );
            }
        }
    }

    /**
     * @param Result $result
     * @param string $base
     * @param string $vhost
     * @param array $amqp
     * @return void
     */
    private function addQueueRows(Result $result, string $base, string $vhost, array $amqp): void
    {
        $url = $base . '/api/queues/' . rawurlencode($vhost)
            . '?page=1&page_size=' . self::MAX_QUEUES
            . '&sort=messages&sort_reverse=true'
            . '&columns=name,messages,messages_ready,messages_unacknowledged,consumers,state';

        $queues = $this->getJson($url, $amqp);
        if (!is_array($queues)) {
            return;
        }
        // RabbitMQ 3.7+ answers a paginated request with an envelope; older
        // versions ignore the paging arguments and return a bare list.
        if (isset($queues['items']) && is_array($queues['items'])) {
            $queues = $queues['items'];
        }

        $section = 'Queues';
        if ($queues === []) {
            $result->add($section, 'Queues', 'None declared', Status::INFO);

            return;
        }

        foreach ($queues as $queue) {
            if (!is_array($queue)) {
                continue;
            }
            $depth = (int) ($queue['messages'] ?? 0);
            $consumers = (int) ($queue['consumers'] ?? 0);

            $status = Status::OK;
            $hint = sprintf('%d consumer%s.', $consumers, $consumers === 1 ? '' : 's');
            if ($depth > 0 && $consumers === 0) {
                $status = Status::ERROR;
                $hint = 'Backlog with no consumer attached — check that bin/magento queue:consumers:start is running.';
            } elseif ($depth >= self::BACKLOG_WARN) {
                $status = Status::WARN;
                $hint = sprintf('%d consumer%s, not keeping up.', $consumers, $consumers === 1 ? '' : 's');
            }

            $result->add(
                $section,
                (string) ($queue['name'] ?? '?'),
                sprintf(
                    '%s ready, %s unacked',
                    $this->formatter->number($queue['messages_ready'] ?? 0),
                    $this->formatter->number($queue['messages_unacknowledged'] ?? 0)
                ),
                $status,
                $hint
            );
        }
    }

    /**
     * @param string $host
     * @return string
     */
    private function resolveManagementUrl(string $host): string
    {
        $override = $this->config->getRabbitMqManagementUrl();

        return $override !== '' ? $override : sprintf('http://%s:%d', $host, self::DEFAULT_MANAGEMENT_PORT);
    }

    /**
     * @param string $url
     * @param array $amqp
     * @return array|null
     */
    private function getJson(string $url, array $amqp): ?array
    {
        return $this->fetcher->fetch(
            $url,
            (string) ($amqp['user'] ?? ''),
            (string) ($amqp['password'] ?? '')
        );
    }
}
