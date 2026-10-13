<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

use Magenx\Platform\Model\CollectorPool;
use Magenx\Platform\Model\CollectorRunner;
use Magenx\Platform\Model\Config;
use Magento\Framework\App\ResourceConnection;

/**
 * The versions of the services this store actually runs on.
 *
 * PHP and the database are asked directly: one constant and one `SELECT
 * VERSION()` cost nothing and need no other module switched on. The search
 * engine, Redis and RabbitMQ are read from the Platform module's collectors,
 * which already hold their connection details and cache what they measure, so
 * this never opens a second connection to any of them. A collector the store
 * has not enabled is reported as unmeasured rather than probed anyway.
 *
 * Each measured component is named by the key the advisory API uses for it in
 * a release's system requirements — `mariadb` or `mysql`, `opensearch` or
 * `elasticsearch` — so the two can be compared without a translation table.
 *
 * The pool arrives as a proxy (etc/di.xml): the Compatibility tab is itself in
 * that pool and reaches this class, so a real pool here would be a cycle.
 */
class EnvironmentVersions
{
    /** Platform collector code => the component it measures. */
    private const COLLECTED = ['opensearch' => 'search', 'redis' => 'cache', 'rabbitmq' => 'queue'];

    /** @var array{measured: array<int, array<string, string>>, unmeasured: array<int, array<string, string>>}|null */
    private ?array $versions = null;

    private ResourceConnection $resource;

    private Config $config;

    private CollectorPool $pool;

    private CollectorRunner $runner;

    /**
     * @param ResourceConnection $resource
     * @param Config $config
     * @param CollectorPool $pool
     * @param CollectorRunner $runner
     */
    public function __construct(
        ResourceConnection $resource,
        Config $config,
        CollectorPool $pool,
        CollectorRunner $runner
    ) {
        $this->resource = $resource;
        $this->config = $config;
        $this->pool = $pool;
        $this->runner = $runner;
    }

    /**
     * Each measured service as `{component, product, version, source}`, and
     * each one that could not be as `{component, reason}`.
     *
     * @return array{measured: array<int, array<string, string>>, unmeasured: array<int, array<string, string>>}
     */
    public function get(): array
    {
        if ($this->versions !== null) {
            return $this->versions;
        }

        $measured = [
            ['component' => 'php', 'product' => 'php', 'version' => PHP_VERSION, 'source' => 'runtime'],
        ];
        $unmeasured = [];

        try {
            $raw = (string) $this->resource->getConnection()->fetchOne('SELECT VERSION()');
            $measured[] = [
                'component' => 'database',
                'product' => stripos($raw, 'mariadb') !== false ? 'mariadb' : 'mysql',
                'version' => $raw,
                'source' => 'database',
            ];
        } catch (\Throwable $e) {
            $unmeasured[] = ['component' => 'database', 'reason' => $e->getMessage()];
        }

        $enabled = $this->config->isEnabled() ? $this->config->getEnabledCollectors() : [];
        foreach (self::COLLECTED as $code => $component) {
            $reading = $this->readCollector($code, $enabled);
            if (isset($reading['reason'])) {
                $unmeasured[] = ['component' => $component, 'reason' => $reading['reason']];
                continue;
            }
            $measured[] = ['component' => $component] + $reading + ['source' => 'platform:' . $code];
        }

        return $this->versions = ['measured' => $measured, 'unmeasured' => $unmeasured];
    }

    /**
     * The product and version one Platform collector reports in its "Version"
     * row, or a reason it could not be read.
     *
     * @param string $code
     * @param string[] $enabled
     * @return array{product?: string, version?: string, reason?: string}
     */
    private function readCollector(string $code, array $enabled): array
    {
        if (!in_array($code, $enabled, true)) {
            return ['reason' => sprintf(
                'The "%s" tab is not enabled (Stores > Configuration > Magenx > Platform Overview > Tabs).',
                $code
            )];
        }

        $collector = $this->pool->get($code);
        if ($collector === null) {
            return ['reason' => sprintf('No tab is registered under "%s".', $code)];
        }

        $payload = $this->runner->run($code, $collector);
        foreach ((array) ($payload['sections'] ?? []) as $section) {
            foreach ((array) ($section['rows'] ?? []) as $row) {
                if (($row['label'] ?? null) !== 'Version') {
                    continue;
                }
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '' || $value === 'n/a') {
                    break 2;
                }

                return ['product' => $this->productFor($code, $value), 'version' => $value];
            }
        }

        return ['reason' => sprintf(
            'The "%s" tab did not report a version: %s',
            $code,
            (string) ($payload['summary'] ?? 'no reading')
        )];
    }

    /**
     * The search collector reports "Opensearch 2.19.5" or "Elasticsearch 8.17.0";
     * the others measure exactly one product.
     *
     * @param string $code
     * @param string $value
     * @return string
     */
    private function productFor(string $code, string $value): string
    {
        if ($code === 'opensearch') {
            return stripos($value, 'elasticsearch') === 0 ? 'elasticsearch' : 'opensearch';
        }

        return $code;
    }
}
