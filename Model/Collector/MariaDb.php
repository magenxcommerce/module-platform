<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Collector;

use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\ResultFactory;
use Magenx\Platform\Model\Metric\Status;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;

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

    private ResultFactory $resultFactory;

    private Formatter $formatter;

    private Status $status;

    /**
     * @param ResourceConnection $resource
     * @param DeploymentConfig $deploymentConfig
     * @param ResultFactory $resultFactory
     * @param Formatter $formatter
     * @param Status $status
     */
    public function __construct(
        ResourceConnection $resource,
        DeploymentConfig $deploymentConfig,
        ResultFactory $resultFactory,
        Formatter $formatter,
        Status $status
    ) {
        $this->resource = $resource;
        $this->deploymentConfig = $deploymentConfig;
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
        $this->addStorageRows($result, $connection, $schema);
        $this->addQueryDigestRows($result, $connection, $variables);

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
        $hitPct = $requests > 0 ? (1 - ($reads / $requests)) * 100 : 100.0;

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
     * @param Result $result
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $schema
     * @return void
     */
    private function addStorageRows(Result $result, $connection, string $schema): void
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
        $sizes = $connection->fetchPairs(
            'SELECT table_name, data_length + index_length AS total_size '
            . 'FROM information_schema.TABLES WHERE table_schema = ? '
            . 'ORDER BY total_size DESC',
            [$schema]
        );

        $result->add($section, 'Schema Size', $this->formatter->bytes(array_sum(array_map('floatval', $sizes))));

        foreach (array_slice($sizes, 0, self::LARGEST_TABLES, true) as $table => $size) {
            $result->add($section, (string) $table, $this->formatter->bytes($size));
        }
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
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param array $variables
     * @return void
     */
    private function addQueryDigestRows(Result $result, $connection, array $variables): void
    {
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
        } catch (\Throwable $e) {
            $result->add(
                $section,
                'performance_schema',
                'No access',
                Status::INFO,
                'The Magento database user needs SELECT on performance_schema to read statement digests.'
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
