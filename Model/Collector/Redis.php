<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Collector;

use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\ResultFactory;
use Magenx\Platform\Model\Metric\Status;
use Magento\Framework\App\DeploymentConfig;

/**
 * One card per Redis instance Magento is configured to use — default cache,
 * page cache and session — each probed with the connection details already in
 * app/etc/env.php.
 *
 * Connections are made with \Credis_Client, which Magento pulls in through
 * colinmollenhour/credis and which speaks the protocol in pure PHP. That is
 * deliberate: it means this tab works on a container built without ext-redis.
 */
class Redis implements CollectorInterface
{
    private const MEMORY_WARN_PCT = 80.0;
    private const MEMORY_ERROR_PCT = 95.0;

    /** A cache whose hit rate has collapsed is doing more harm than good. */
    private const HIT_RATE_WARN_PCT = 80.0;
    private const HIT_RATE_ERROR_PCT = 50.0;

    /**
     * label => [deployment config path, host key, is this a cache (vs sessions)]
     */
    private const INSTANCES = [
        'Default Cache' => ['cache/frontend/default/backend_options', 'server', true],
        'Page Cache' => ['cache/frontend/page_cache/backend_options', 'server', true],
        'Sessions' => ['session/redis', 'host', false],
    ];

    private DeploymentConfig $deploymentConfig;

    private ResultFactory $resultFactory;

    private Formatter $formatter;

    private Status $status;

    private Config $config;

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param ResultFactory $resultFactory
     * @param Formatter $formatter
     * @param Status $status
     * @param Config $config
     */
    public function __construct(
        DeploymentConfig $deploymentConfig,
        ResultFactory $resultFactory,
        Formatter $formatter,
        Status $status,
        Config $config
    ) {
        $this->deploymentConfig = $deploymentConfig;
        $this->resultFactory = $resultFactory;
        $this->formatter = $formatter;
        $this->status = $status;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Redis';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        /** @var Result $result */
        $result = $this->resultFactory->create();

        // Kept even though composer.json requires credis: a module dropped into
        // app/code has its composer.json ignored entirely, and credis reaches a
        // Magento install through magento/product-community-edition rather than
        // through magento/framework — so a project assembled from framework
        // packages alone can genuinely be without it. One red tab beats a fatal.
        if (!class_exists(\Credis_Client::class)) {
            return $result->setStatus(Status::UNAVAILABLE)
                ->setSummary('colinmollenhour/credis is not installed, so Redis cannot be probed.');
        }

        // A session/redis block survives in app/etc/env.php after session/save
        // is switched away from redis, and probing it then reports on an
        // instance Magento never touches — and grades it, so an evicted key on
        // an idle server reads as "logs a customer out mid-checkout".
        $sessionsInUse = (string) $this->deploymentConfig->get('session/save') === 'redis';

        $configured = 0;
        foreach (self::INSTANCES as $label => [$path, $hostKey, $isCache]) {
            $options = $this->deploymentConfig->get($path);
            if (!is_array($options)) {
                continue;
            }
            if ($path === 'session/redis' && !$sessionsInUse) {
                // Said out loud rather than silently dropped: a missing card is
                // indistinguishable from a broken collector.
                $result->add(
                    $label,
                    'In Use',
                    'No',
                    Status::INFO,
                    'app/etc/env.php still carries a session/redis block, but session/save is not "redis", '
                    . 'so Magento stores sessions elsewhere and this instance is not probed.'
                );

                continue;
            }
            // A remote-synchronized cache nests the real Redis options one level down.
            if (isset($options['remote_backend_options']) && is_array($options['remote_backend_options'])) {
                $options = $options['remote_backend_options'];
            }
            $host = (string) ($options[$hostKey] ?? $options['server'] ?? $options['host'] ?? '');
            if ($host === '') {
                continue;
            }
            $configured++;
            $this->addInstance($result, $label, $host, $options, $isCache);
        }

        if ($configured === 0) {
            return $result->setStatus(Status::UNAVAILABLE)->setSummary(
                $sessionsInUse
                    ? 'No Redis instance is configured in app/etc/env.php.'
                    : 'No Redis instance is in use: nothing caches to Redis, and session/save is not "redis".'
            );
        }

        $result->setSummary(sprintf('%d instance%s configured', $configured, $configured === 1 ? '' : 's'));

        return $result;
    }

    /**
     * @param Result $result
     * @param string $label
     * @param string $host
     * @param array $options
     * @param bool $isCache
     * @return void
     */
    private function addInstance(Result $result, string $label, string $host, array $options, bool $isCache): void
    {
        $port = (int) ($options['port'] ?? 6379);
        $database = (int) ($options['database'] ?? 0);
        $password = (string) ($options['password'] ?? '');

        $result->add($label, 'Endpoint', $this->describeEndpoint($host, $port, $database));

        try {
            $info = $this->readInfo($host, $port, $database, $password);
        } catch (\Throwable $e) {
            // Isolated per instance: a dead session Redis must not hide a healthy
            // page cache on the same tab.
            $result->add($label, 'Reachable', 'No', Status::ERROR, $e->getMessage());

            return;
        }

        $result->add($label, 'Version', (string) ($info['redis_version'] ?? 'n/a'));
        $result->add($label, 'Role', (string) ($info['role'] ?? 'n/a'));
        $result->add($label, 'Uptime', $this->formatter->duration($info['uptime_in_seconds'] ?? 0));
        $result->add($label, 'Connected Clients', $this->formatter->number($info['connected_clients'] ?? 0));

        $this->addMemoryRows($result, $label, $info, $isCache);
        $this->addTrafficRows($result, $label, $info, $isCache);
        $this->addKeyspaceRow($result, $label, $info, $database);
    }

    /**
     * @param Result $result
     * @param string $label
     * @param array $info
     * @param bool $isCache
     * @return void
     */
    private function addMemoryRows(Result $result, string $label, array $info, bool $isCache): void
    {
        $used = (float) ($info['used_memory'] ?? 0);
        $max = (float) ($info['maxmemory'] ?? 0);
        $policy = (string) ($info['maxmemory_policy'] ?? 'noeviction');

        if ($max > 0) {
            $usedPct = $this->formatter->ratio($used, $max);
            $result->add(
                $label,
                'Memory',
                $this->formatter->bytesOf($used, $max),
                $this->status->forCeiling($usedPct, self::MEMORY_WARN_PCT, self::MEMORY_ERROR_PCT),
                'Against maxmemory.'
            );
        } else {
            $result->add(
                $label,
                'Memory',
                $this->formatter->bytes($used),
                $isCache ? Status::WARN : Status::INFO,
                'No maxmemory is set, so this instance will grow until the container is killed by the OOM reaper. '
                . 'A cache instance should have maxmemory and an eviction policy.'
            );
        }

        $result->add(
            $label,
            'Eviction Policy',
            $policy,
            $isCache && $policy === 'noeviction' ? Status::WARN : Status::INFO,
            $isCache
                ? 'A cache with noeviction starts refusing writes when full instead of dropping cold keys.'
                : 'Sessions must not be evicted — noeviction is the correct policy here.'
        );
    }

    /**
     * @param Result $result
     * @param string $label
     * @param array $info
     * @param bool $isCache
     * @return void
     */
    private function addTrafficRows(Result $result, string $label, array $info, bool $isCache): void
    {
        $hits = (float) ($info['keyspace_hits'] ?? 0);
        $misses = (float) ($info['keyspace_misses'] ?? 0);
        $lookups = $hits + $misses;

        if ($lookups > 0) {
            $hitPct = $this->formatter->ratio($hits, $lookups);
            $result->add(
                $label,
                'Hit Rate',
                $this->formatter->percent($hitPct),
                $isCache
                    ? $this->status->forFloor($hitPct, self::HIT_RATE_WARN_PCT, self::HIT_RATE_ERROR_PCT)
                    : Status::INFO,
                'Since the instance last started.'
            );
        }

        $evicted = (float) ($info['evicted_keys'] ?? 0);
        $result->add(
            $label,
            'Evicted Keys',
            $this->formatter->number($evicted),
            $evicted > 0 ? ($isCache ? Status::WARN : Status::ERROR) : Status::OK,
            $isCache
                ? 'Evictions mean the instance is undersized for the working set.'
                : 'An evicted session key logs a customer out mid-checkout.'
        );
        $result->add($label, 'Expired Keys', $this->formatter->number($info['expired_keys'] ?? 0));
        $result->add($label, 'Ops/sec', $this->formatter->number($info['instantaneous_ops_per_sec'] ?? 0));

        $lastSave = (string) ($info['rdb_last_bgsave_status'] ?? 'ok');
        $result->add(
            $label,
            'Last Background Save',
            $lastSave,
            $lastSave === 'ok' ? Status::OK : Status::WARN,
            'A failing bgsave usually means the disk is full or overcommit_memory is unset.'
        );
    }

    /**
     * @param Result $result
     * @param string $label
     * @param array $info
     * @param int $database
     * @return void
     */
    private function addKeyspaceRow(Result $result, string $label, array $info, int $database): void
    {
        $keyspace = $info['db' . $database] ?? null;
        if ($keyspace === null) {
            $result->add($label, 'Keys', '0', Status::INFO, 'Database ' . $database . ' is empty.');

            return;
        }

        // Credis returns this section either preparsed or as "keys=1,expires=0,avg_ttl=0".
        if (is_string($keyspace)) {
            $parsed = [];
            foreach (explode(',', $keyspace) as $pair) {
                $parts = explode('=', $pair, 2);
                if (count($parts) === 2) {
                    $parsed[trim($parts[0])] = trim($parts[1]);
                }
            }
            $keyspace = $parsed;
        }

        $result->add(
            $label,
            'Keys',
            $this->formatter->number($keyspace['keys'] ?? 0),
            Status::INFO,
            sprintf('%s with a TTL.', $this->formatter->number($keyspace['expires'] ?? 0))
        );
    }

    /**
     * @param string $host
     * @param int $port
     * @param int $database
     * @param string $password
     * @return array
     */
    private function readInfo(string $host, int $port, int $database, string $password): array
    {
        $client = new \Credis_Client(
            $host,
            $this->isUnixSocket($host) ? null : $port,
            $this->config->getTimeout(),
            '',
            $database,
            $password !== '' ? $password : null
        );

        try {
            $client->connect();
            // Credis applies the constructor timeout to the connect only. A
            // Redis that accepts the socket and then never answers INFO — a
            // node mid-failover, or one busy with a large BGSAVE — would
            // otherwise hold this request open well past the configured
            // timeout, which is the exact failure mode the timeout exists to
            // prevent.
            $client->setReadTimeout($this->config->getTimeout());
            $info = $client->info();
        } finally {
            // The password never leaves this method, and the socket never
            // outlives the probe.
            try {
                $client->close();
            } catch (\Throwable) {
                // Nothing useful to do about a socket that will not close.
            }
        }

        return is_array($info) ? $info : [];
    }

    /**
     * @param string $host
     * @param int $port
     * @param int $database
     * @return string
     */
    private function describeEndpoint(string $host, int $port, int $database): string
    {
        return $this->isUnixSocket($host)
            ? sprintf('%s db %d', $host, $database)
            : sprintf('%s:%d db %d', $host, $port, $database);
    }

    /**
     * @param string $host
     * @return bool
     */
    private function isUnixSocket(string $host): bool
    {
        return str_starts_with($host, '/');
    }
}
