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
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * MariaDB / MySQL health, over the connection Magento already holds.
 *
 * Everything here comes from SHOW GLOBAL STATUS, SHOW GLOBAL VARIABLES, a
 * single information_schema pass and two performance_schema digest queries. No
 * credential is read for this collector at all — ResourceConnection hands back
 * a live connection — and the host and schema shown come from
 * db/connection/default purely so the admin can tell which database they are
 * looking at.
 */
class MariaDb implements CollectorInterface
{
    /** Connection saturation. Past 90% new PHP workers start failing outright. */
    private const CONNECTIONS_WARN_PCT = 70.0;
    private const CONNECTIONS_ERROR_PCT = 90.0;

    /** Below 99% the buffer pool is too small for the working set. */
    private const BUFFER_POOL_HIT_WARN_PCT = 99.0;
    private const BUFFER_POOL_HIT_ERROR_PCT = 95.0;

    private const LARGEST_TABLES = 5;

    private const TOP_QUERIES = 5;

    /** Digests are long; a row label has to stay readable. */
    private const DIGEST_LABEL_LENGTH = 110;

    private ResourceConnection $resource;

    private DeploymentConfig $deploymentConfig;

    private Config $config;

    private ResultFactory $resultFactory;

    private Formatter $formatter;

    private Status $status;

    /**
     * @param ResourceConnection $resource
     * @param DeploymentConfig $deploymentConfig
     * @param Config $config
     * @param ResultFactory $resultFactory
     * @param Formatter $formatter
     * @param Status $status
     */
    public function __construct(
        ResourceConnection $resource,
        DeploymentConfig $deploymentConfig,
        Config $config,
        ResultFactory $resultFactory,
        Formatter $formatter,
        Status $status
    ) {
        $this->resource = $resource;
        $this->deploymentConfig = $deploymentConfig;
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
        return 'MariaDB';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        $connection = $this->resource->getConnection();
        /** @var Result $result */
        $result = $this->resultFactory->create();

        $globalStatus = $connection->fetchPairs('SHOW GLOBAL STATUS');
        $variables = $connection->fetchPairs('SHOW GLOBAL VARIABLES');
        // SHOW GLOBAL VARIABLES already carries the server version, so there is
        // no SELECT VERSION() round trip to make for it.
        $version = (string) ($variables['version'] ?? 'n/a');
        $schema = (string) $this->deploymentConfig->get('db/connection/default/dbname');

        $result->setSummary(sprintf('%s, up %s', $version, $this->formatter->duration($globalStatus['Uptime'] ?? 0)));

        $this->addServerRows($result, $version, $schema, $globalStatus, $variables);
        $this->addConnectionRows($result, $globalStatus, $variables);
        $this->addInnoDbRows($result, $globalStatus, $variables);
        $this->addQueryRows($result, $globalStatus, $variables);
        $this->addQueryCacheRows($result, $globalStatus, $variables);

        // The only two slow reads on this tab, sharing one timeout window.
        $this->withStatementTimeout(
            $connection,
            $variables,
            function () use ($result, $connection, $schema, $variables): void {
                $this->addStorageRows($result, $connection, $schema);
                $this->addQueryDigestRows($result, $connection, $variables);
            }
        );

        return $result;
    }

    /**
     * @param Result $result
     * @param string $version
     * @param string $schema
     * @param array $globalStatus
     * @param array $variables
     * @return void
     */
    private function addServerRows(
        Result $result,
        string $version,
        string $schema,
        array $globalStatus,
        array $variables
    ): void {
        $section = 'Server';
        $host = (string) $this->deploymentConfig->get('db/connection/default/host');

        $result->add($section, 'Version', $version);
        $result->add($section, 'Host', $host !== '' ? $host : 'n/a');
        $result->add($section, 'Schema', $schema !== '' ? $schema : 'n/a');
        $result->add($section, 'Uptime', $this->formatter->duration($globalStatus['Uptime'] ?? 0));
        $result->add(
            $section,
            'Read Only',
            ($variables['read_only'] ?? 'OFF') === 'ON' ? 'ON' : 'OFF',
            ($variables['read_only'] ?? 'OFF') === 'ON' ? Status::ERROR : Status::OK,
            'A read-only server cannot take orders.'
        );
    }

    /**
     * @param Result $result
     * @param array $globalStatus
     * @param array $variables
     * @return void
     */
    private function addConnectionRows(Result $result, array $globalStatus, array $variables): void
    {
        $section = 'Connections';
        $used = (float) ($globalStatus['Threads_connected'] ?? 0);
        $max = (float) ($variables['max_connections'] ?? 0);
        $usedPct = $this->formatter->ratio($used, $max);

        $result->add(
            $section,
            'Threads Connected',
            sprintf('%s / %s (%s)', $this->formatter->number($used), $this->formatter->number($max), $this->formatter->percent($usedPct)),
            $this->status->forCeiling($usedPct, self::CONNECTIONS_WARN_PCT, self::CONNECTIONS_ERROR_PCT),
            'Against max_connections. PHP-FPM workers fail to connect once this fills.'
        );
        $result->add($section, 'Threads Running', $this->formatter->number($globalStatus['Threads_running'] ?? 0));
        $result->add(
            $section,
            'Max Used Connections',
            $this->formatter->number($globalStatus['Max_used_connections'] ?? 0),
            Status::INFO,
            'The high-water mark since the server started.'
        );

        $refused = (float) ($globalStatus['Connection_errors_max_connections'] ?? 0);
        $result->add(
            $section,
            'Refused (max_connections)',
            $this->formatter->number($refused),
            $refused > 0 ? Status::ERROR : Status::OK,
            'Connections the server turned away because it was full.'
        );
        $result->add(
            $section,
            'Aborted Connects',
            $this->formatter->number($globalStatus['Aborted_connects'] ?? 0),
            Status::INFO,
            'Usually failed authentication or a client that gave up mid-handshake.'
        );
    }

    /**
     * @param Result $result
     * @param array $globalStatus
     * @param array $variables
     * @return void
     */
    private function addInnoDbRows(Result $result, array $globalStatus, array $variables): void
    {
        $section = 'InnoDB Buffer Pool';
        $requests = (float) ($globalStatus['Innodb_buffer_pool_read_requests'] ?? 0);
        $reads = (float) ($globalStatus['Innodb_buffer_pool_reads'] ?? 0);
        // ratio() already carries the zero-denominator guard, and a pool that
        // has served no requests yet has missed none of them.
        $hitPct = $requests > 0 ? 100 - $this->formatter->ratio($reads, $requests) : 100.0;

        $result->add(
            $section,
            'Hit Rate',
            $this->formatter->percent($hitPct, 2),
            $this->status->forFloor($hitPct, self::BUFFER_POOL_HIT_WARN_PCT, self::BUFFER_POOL_HIT_ERROR_PCT),
            'Share of page reads served from memory. Below 99% the pool is too small for the working set.'
        );
        $result->add($section, 'Pool Size', $this->formatter->bytes($variables['innodb_buffer_pool_size'] ?? 0));
        $result->add(
            $section,
            'Data In Pool',
            $this->formatter->bytes($globalStatus['Innodb_buffer_pool_bytes_data'] ?? 0),
            Status::INFO,
            'Sitting well below the pool size means the pool is oversized for this dataset.'
        );
        $result->add($section, 'Dirty Pages', $this->formatter->bytes($globalStatus['Innodb_buffer_pool_bytes_dirty'] ?? 0));
        $result->add(
            $section,
            'Row Lock Waits',
            $this->formatter->number($globalStatus['Innodb_row_lock_waits'] ?? 0),
            Status::INFO,
            'Rising fast during checkout points at contention on quote or inventory rows.'
        );
    }

    /**
     * @param Result $result
     * @param array $globalStatus
     * @param array $variables
     * @return void
     */
    private function addQueryRows(Result $result, array $globalStatus, array $variables): void
    {
        $section = 'Queries';
        $uptime = max(1.0, (float) ($globalStatus['Uptime'] ?? 1));
        $questions = (float) ($globalStatus['Questions'] ?? 0);
        $slow = (float) ($globalStatus['Slow_queries'] ?? 0);

        $result->add($section, 'Average QPS', sprintf('%.1f/s', $questions / $uptime), Status::INFO, 'Averaged over the whole uptime, not a live rate.');
        $result->add(
            $section,
            'Slow Queries',
            $this->formatter->number($slow),
            $slow > 0 ? Status::WARN : Status::OK,
            sprintf(
                'Queries over long_query_time (%s s). Slow query log is %s.',
                $variables['long_query_time'] ?? '?',
                ($variables['slow_query_log'] ?? 'OFF') === 'ON' ? 'on' : 'off'
            )
        );
        $result->add(
            $section,
            'Table Lock Waits',
            $this->formatter->number($globalStatus['Table_locks_waited'] ?? 0),
            Status::INFO,
            'Non-zero on InnoDB usually means a MyISAM or MEMORY table is in a hot path.'
        );
        $result->add(
            $section,
            'Created Temp Disk Tables',
            $this->formatter->number($globalStatus['Created_tmp_disk_tables'] ?? 0),
            Status::INFO,
            'Temporary tables that spilled to disk — the usual cause is a large sort or group by in a report.'
        );
    }

    /**
     * The query cache — which on this workload is a thing to have switched off.
     *
     * MariaDB still ships it and MySQL removed it in 8.0, so which rows this
     * builds is decided by what the server publishes rather than by a version
     * string. Where it exists it is one global mutex in front of every SELECT,
     * and any write to a table throws away every cached result for that table.
     * A Magento database writes constantly — quotes, sessions, index and cache
     * tables — so the cache is emptied about as fast as it fills while every
     * core queues behind that one lock.
     *
     * Hence the shape of these rows: "off" is reported as a healthy state
     * rather than as an absence, and "on" is a warning that says what to look
     * at next.
     *
     * @param Result $result
     * @param array $globalStatus
     * @param array $variables
     * @return void
     */
    private function addQueryCacheRows(Result $result, array $globalStatus, array $variables): void
    {
        $section = 'Query Cache';

        if (!array_key_exists('query_cache_type', $variables) && !array_key_exists('query_cache_size', $variables)) {
            $result->add(
                $section,
                'Query Cache',
                'Not available',
                Status::OK,
                'This server has no query cache at all — MySQL removed it in 8.0. Nothing to tune.'
            );

            return;
        }

        // "0" and "OFF" are the same setting said two ways, and a type of ON
        // with a size of zero caches nothing either. DEMAND, which caches only
        // statements marked SQL_CACHE, still holds the mutex, so it counts as
        // on.
        $type = strtoupper(trim((string) ($variables['query_cache_type'] ?? 'OFF')));
        $size = (float) ($variables['query_cache_size'] ?? 0);

        if ($type === 'OFF' || $type === '0' || $size <= 0) {
            $result->add(
                $section,
                'Query Cache',
                'Off',
                Status::OK,
                sprintf(
                    'query_cache_type=%s, query_cache_size=%s. Off is the right setting here: the cache '
                    . 'serialises every SELECT behind one global lock and Magento invalidates it faster '
                    . 'than it fills.',
                    $type,
                    $this->formatter->bytes($size)
                )
            );

            return;
        }

        $result->add(
            $section,
            'Query Cache',
            sprintf('On (query_cache_type=%s)', $type),
            Status::WARN,
            'Every SELECT takes a global mutex to look here first, and every write to a table drops all '
            . 'cached results for that table. On a Magento database that costs throughput on all cores to '
            . 'serve a cache that is continuously emptied. The rows below say what it is actually buying.'
        );

        $free = (float) ($globalStatus['Qcache_free_memory'] ?? 0);
        $result->add(
            $section,
            'Memory',
            $this->formatter->bytesOf($size - $free, $size),
            Status::INFO,
            'Against query_cache_size.'
        );

        // Hits against hits plus the selects that had to be executed. Com_select
        // counts only the statements that missed, so the two together are the
        // reads the cache was asked about.
        $hits = (float) ($globalStatus['Qcache_hits'] ?? 0);
        $selects = (float) ($globalStatus['Com_select'] ?? 0);
        $result->add(
            $section,
            'Hit Rate',
            sprintf(
                '%s (%s hits, %s executed)',
                $this->formatter->percent($this->formatter->ratio($hits, $hits + $selects), 2),
                $this->formatter->number($hits),
                $this->formatter->number($selects)
            ),
            Status::INFO,
            'A low rate means the mutex is being paid for on every read and almost nothing is coming back '
            . 'from the cache.'
        );

        $result->add(
            $section,
            'Cached Queries',
            $this->formatter->number($globalStatus['Qcache_queries_in_cache'] ?? 0),
            Status::INFO,
            'Result sets held right now.'
        );
        $result->add(
            $section,
            'Inserts',
            $this->formatter->number($globalStatus['Qcache_inserts'] ?? 0),
            Status::INFO,
            'Result sets stored since the server started.'
        );
        $result->add(
            $section,
            'Not Cached',
            $this->formatter->number($globalStatus['Qcache_not_cached'] ?? 0),
            Status::INFO,
            sprintf(
                'SELECTs the cache would not take — a non-deterministic function, a temporary table, or a '
                . 'result larger than query_cache_limit (%s).',
                $this->formatter->bytes($variables['query_cache_limit'] ?? 0)
            )
        );

        $prunes = (float) ($globalStatus['Qcache_lowmem_prunes'] ?? 0);
        $result->add(
            $section,
            'Low-memory Prunes',
            $this->formatter->number($prunes),
            $prunes > 0 ? Status::WARN : Status::OK,
            'Results evicted to make room for newer ones. Either the cache is too small for the traffic or '
            . 'it is too fragmented to reuse what it has freed.'
        );

        $freeBlocks = (float) ($globalStatus['Qcache_free_blocks'] ?? 0);
        $totalBlocks = (float) ($globalStatus['Qcache_total_blocks'] ?? 0);
        if ($totalBlocks > 0) {
            $result->add(
                $section,
                'Free Blocks',
                sprintf(
                    '%s of %s (%s)',
                    $this->formatter->number($freeBlocks),
                    $this->formatter->number($totalBlocks),
                    $this->formatter->percent($this->formatter->ratio($freeBlocks, $totalBlocks))
                ),
                Status::INFO,
                'Many free blocks against few total is a fragmented cache; FLUSH QUERY CACHE defragments it '
                . 'without emptying it.'
            );
        }
    }

    /**
     * @param Result $result
     * @param AdapterInterface $connection
     * @param string $schema
     * @return void
     */
    private function addStorageRows(Result $result, AdapterInterface $connection, string $schema): void
    {
        if ($schema === '') {
            return;
        }

        $section = 'Storage';

        // One pass, not two. Querying information_schema.TABLES opens every
        // table in the schema to fill in the size columns, which on a Magento
        // database of several hundred tables is the most expensive thing this
        // collector does — so the schema total and the largest tables are read
        // from the same scan and the total is summed here rather than by a
        // second SUM() query over the same rows.
        try {
            $sizes = $connection->fetchPairs(
                'SELECT table_name, data_length + index_length AS total_size '
                . 'FROM information_schema.TABLES WHERE table_schema = ? '
                . 'ORDER BY total_size DESC',
                [$schema]
            );
        } catch (\Throwable) {
            // Reported as one note, not as a dead tab. This is the only slow
            // query on the collector, and losing it must not take the server,
            // connection, InnoDB and query rows that already succeeded down
            // with it.
            $result->add(
                $section,
                'Schema Size',
                'Not read',
                Status::INFO,
                sprintf(
                    'The information_schema scan did not finish inside the %d second backend timeout. '
                    . 'That is normal on a schema with many thousands of tables — every other row on '
                    . 'this tab is unaffected.',
                    $this->config->getTimeout()
                )
            );

            return;
        }

        $result->add($section, 'Schema Size', $this->formatter->bytes(array_sum(array_map('floatval', $sizes))));

        foreach (array_slice($sizes, 0, self::LARGEST_TABLES, true) as $table => $size) {
            $result->add($section, (string) $table, $this->formatter->bytes($size));
        }
    }

    /**
     * Run the slow reads with the configured backend timeout applied.
     *
     * The timeout was reaching only StatusFetcher's curl handles, so the two
     * genuinely slow queries on this tab ran to completion or to PHP's
     * max_execution_time — which the README promised they would not.
     *
     * A session variable rather than MariaDB's `SET STATEMENT ... FOR` wrapper
     * or MySQL's MAX_EXECUTION_TIME hint, deliberately: Zend_Db prepares every
     * statement it issues, MariaDB does not allow SET STATEMENT to be prepared,
     * and whether that even surfaces depends on PDO::ATTR_EMULATE_PREPARES. A
     * plain SET is preparable on both servers, so this behaves the same way
     * everywhere.
     *
     * SHOW GLOBAL VARIABLES has already been read by the time this is called,
     * so the flavour is known without an extra round trip and the value to put
     * back is known exactly.
     *
     * @param AdapterInterface $connection
     * @param array $variables
     * @param callable $read
     * @return void
     */
    private function withStatementTimeout(AdapterInterface $connection, array $variables, callable $read): void
    {
        $variable = $this->timeoutVariable($variables);

        if ($variable === null) {
            // A server that publishes neither is left unbounded rather than
            // guessed at.
            $read();

            return;
        }

        $seconds = $this->config->getTimeout();

        // Both literals are built by sprintf from a number, so neither the
        // limit nor the value being restored can reach the SQL as free text.
        if ($variable === 'max_statement_time') {
            // MariaDB 10.1+: seconds, held as a double.
            $limit = sprintf('%.3F', $seconds);
            $previous = sprintf('%.6F', (float) $variables[$variable]);
        } else {
            // MySQL 5.7.8+: milliseconds, held as an integer.
            $limit = sprintf('%d', $seconds * 1000);
            $previous = sprintf('%d', (int) $variables[$variable]);
        }

        try {
            $connection->query(sprintf('SET SESSION %s = %s', $variable, $limit));
        } catch (\Throwable) {
            // Not every managed server lets a client set these. Reading
            // unbounded is worse than reading bounded, but far better than
            // losing the section.
            $read();

            return;
        }

        try {
            $read();
        } finally {
            // Always put it back. Magento reuses this connection for the rest
            // of the request, and leaving a three-second ceiling on every later
            // query would be a spectacular way to break checkout.
            try {
                $connection->query(sprintf('SET SESSION %s = %s', $variable, $previous));
            } catch (\Throwable) {
                // Nothing useful to do, and the connection dies with the
                // request anyway.
            }
        }
    }

    /**
     * The session variable this server bounds statement runtime with.
     *
     * MariaDB 10.1+ spells it max_statement_time and counts in seconds; MySQL
     * 5.7.8+ spells it max_execution_time and counts in milliseconds. Neither
     * server accepts the other's name, and only one of the two is ever present.
     *
     * @param array $variables
     * @return string|null
     */
    private function timeoutVariable(array $variables): ?string
    {
        if (isset($variables['max_statement_time'])) {
            return 'max_statement_time';
        }

        if (isset($variables['max_execution_time'])) {
            return 'max_execution_time';
        }

        return null;
    }

    /**
     * Which statements actually dominate this server, from performance_schema.
     *
     * These are the two questions worth asking of a slow database — what runs
     * most often, and what burns the most total time — and they are usually
     * different statements. Both are best-effort: performance_schema can be
     * compiled out or switched off, and the Magento database user is often
     * not granted SELECT on it, so every failure here is reported as a note
     * rather than allowed to redden the tab.
     *
     * @param Result $result
     * @param AdapterInterface $connection
     * @param array $variables
     * @return void
     */
    private function addQueryDigestRows(
        Result $result,
        AdapterInterface $connection,
        array $variables
    ): void {
        $section = 'Top Queries';

        if (($variables['performance_schema'] ?? 'OFF') !== 'ON') {
            $result->add(
                $section,
                'performance_schema',
                'Off',
                Status::INFO,
                'Statement digests need performance_schema=ON in the server configuration. It is a restart to enable.'
            );

            return;
        }

        try {
            $byCount = $connection->fetchAll(
                'SELECT DIGEST_TEXT, COUNT_STAR, AVG_TIMER_WAIT / 1000000000000 AS avg_time_sec '
                . 'FROM performance_schema.events_statements_summary_by_digest '
                . 'WHERE SCHEMA_NAME IS NOT NULL '
                . 'ORDER BY COUNT_STAR DESC LIMIT ' . self::TOP_QUERIES
            );
            $byTime = $connection->fetchAll(
                'SELECT DIGEST_TEXT, SUM_TIMER_WAIT / 1000000000000 AS total_time_sec, COUNT_STAR '
                . 'FROM performance_schema.events_statements_summary_by_digest '
                . 'WHERE SCHEMA_NAME IS NOT NULL '
                . 'ORDER BY SUM_TIMER_WAIT DESC LIMIT ' . self::TOP_QUERIES
            );
        } catch (\Throwable) {
            // Two causes, one note: the Magento user usually lacks SELECT here,
            // and on a busy server the digest table can also outrun the backend
            // timeout now that these are bounded.
            $result->add(
                $section,
                'performance_schema',
                'Not read',
                Status::INFO,
                'Statement digests need SELECT on performance_schema for the Magento database user, '
                . 'and a digest table small enough to read inside the backend timeout.'
            );

            return;
        }

        foreach ($byCount as $row) {
            $result->add(
                'Top Queries by Count',
                $this->summarizeDigest($row['DIGEST_TEXT'] ?? null),
                sprintf(
                    '%s calls, %s avg',
                    $this->formatter->number($row['COUNT_STAR'] ?? 0),
                    $this->formatter->seconds((float) ($row['avg_time_sec'] ?? 0))
                ),
                Status::INFO,
                'Counted since the digest table was last reset, across every schema on this server.'
            );
        }

        foreach ($byTime as $row) {
            $result->add(
                'Top Queries by Total Time',
                $this->summarizeDigest($row['DIGEST_TEXT'] ?? null),
                sprintf(
                    '%s total, %s calls',
                    $this->formatter->seconds((float) ($row['total_time_sec'] ?? 0)),
                    $this->formatter->number($row['COUNT_STAR'] ?? 0)
                ),
                Status::INFO,
                'Total time is what a tuning session should start from, not the slowest single execution.'
            );
        }
    }

    /**
     * Squeeze a normalized statement onto one readable line.
     *
     * @param string|null $digest
     * @return string
     */
    private function summarizeDigest(?string $digest): string
    {
        $digest = trim((string) preg_replace('/\s+/', ' ', (string) $digest));
        if ($digest === '') {
            return '(unknown statement)';
        }

        if (mb_strlen($digest) <= self::DIGEST_LABEL_LENGTH) {
            return $digest;
        }

        return mb_substr($digest, 0, self::DIGEST_LABEL_LENGTH - 1) . '…';
    }
}
