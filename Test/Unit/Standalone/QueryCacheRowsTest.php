<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Standalone;

use Magenx\Platform\Model\Collector\MariaDb;
use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The query cache rows, built from SHOW GLOBAL STATUS and SHOW GLOBAL VARIABLES
 * and from nothing else — so the builder is driven here with two literal arrays
 * and no database.
 *
 * The judgement being pinned is the one this section exists for: on a Magento
 * database a query cache that is off is a healthy state and not a missing
 * feature, and one that is on is worth a warning however good its hit rate
 * looks.
 */
#[CoversClass(MariaDb::class)]
class QueryCacheRowsTest extends TestCase
{
    /**
     * @param array $overrides
     * @return array
     */
    private function variables(array $overrides = []): array
    {
        return $overrides + [
            'query_cache_type' => 'OFF',
            'query_cache_size' => '0',
            'query_cache_limit' => '1048576',
        ];
    }

    /**
     * @param array $overrides
     * @return array
     */
    private function globalStatus(array $overrides = []): array
    {
        return $overrides + [
            'Qcache_free_memory' => '50331648',
            'Qcache_free_blocks' => '120',
            'Qcache_total_blocks' => '400',
            'Qcache_hits' => '250000',
            'Qcache_inserts' => '180000',
            'Qcache_lowmem_prunes' => '0',
            'Qcache_not_cached' => '9000',
            'Qcache_queries_in_cache' => '1400',
            'Com_select' => '750000',
        ];
    }

    /**
     * @param array $globalStatus
     * @param array $variables
     * @return array<string, array>
     */
    private function rowsFor(array $globalStatus, array $variables): array
    {
        $reflection = new ReflectionClass(MariaDb::class);
        $collector = $reflection->newInstanceWithoutConstructor();

        foreach (['formatter' => new Formatter(), 'status' => new Status()] as $name => $collaborator) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($collector, $collaborator);
        }

        $result = new Result(new Status());
        $method = $reflection->getMethod('addQueryCacheRows');
        $method->setAccessible(true);
        $method->invokeArgs($collector, [$result, $globalStatus, $variables]);

        $rows = [];
        foreach ($result->toArray()['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $rows[$row['label']] = $row;
            }
        }

        return $rows;
    }

    public function testAServerWithoutAQueryCacheSaysSoInsteadOfGoingQuiet(): void
    {
        // MySQL 8.0 removed the feature, so neither variable is published.
        $rows = $this->rowsFor($this->globalStatus(), ['max_connections' => '500']);

        $this->assertSame(['Query Cache'], array_keys($rows));
        $this->assertSame('Not available', $rows['Query Cache']['value']);
        $this->assertSame(Status::OK, $rows['Query Cache']['status']);
    }

    public function testACacheThatIsOffIsOneHealthyRowAndNoCounters(): void
    {
        $rows = $this->rowsFor($this->globalStatus(), $this->variables());

        $this->assertSame(['Query Cache'], array_keys($rows));
        $this->assertSame('Off', $rows['Query Cache']['value']);
        $this->assertSame(Status::OK, $rows['Query Cache']['status']);
        $this->assertStringContainsString('query_cache_size=0 B', $rows['Query Cache']['hint']);
    }

    public function testATypeOfOnWithNoMemoryBehindItIsStillOff(): void
    {
        $rows = $this->rowsFor(
            $this->globalStatus(),
            $this->variables(['query_cache_type' => 'ON', 'query_cache_size' => '0'])
        );

        $this->assertSame('Off', $rows['Query Cache']['value']);
    }

    public function testAnEnabledCacheIsWarnedAboutAndFullyReported(): void
    {
        $rows = $this->rowsFor(
            $this->globalStatus(),
            $this->variables(['query_cache_type' => 'ON', 'query_cache_size' => '67108864'])
        );

        $this->assertSame(
            [
                'Query Cache',
                'Memory',
                'Hit Rate',
                'Cached Queries',
                'Inserts',
                'Not Cached',
                'Low-memory Prunes',
                'Free Blocks',
            ],
            array_keys($rows)
        );

        $this->assertSame('On (query_cache_type=ON)', $rows['Query Cache']['value']);
        $this->assertSame(Status::WARN, $rows['Query Cache']['status']);
        $this->assertSame('16.0 MB / 64.0 MB (25.0%)', $rows['Memory']['value']);
        // Hits against hits plus the selects that had to run anyway.
        $this->assertSame('25.00% (250 000 hits, 750 000 executed)', $rows['Hit Rate']['value']);
        $this->assertSame('120 of 400 (30.0%)', $rows['Free Blocks']['value']);
        $this->assertStringContainsString('query_cache_limit (1.0 MB)', $rows['Not Cached']['hint']);
    }

    public function testDemandStillCountsAsOnBecauseItStillTakesTheMutex(): void
    {
        $rows = $this->rowsFor(
            $this->globalStatus(),
            $this->variables(['query_cache_type' => 'DEMAND', 'query_cache_size' => '67108864'])
        );

        $this->assertSame('On (query_cache_type=DEMAND)', $rows['Query Cache']['value']);
        $this->assertSame(Status::WARN, $rows['Query Cache']['status']);
    }

    public function testEvictionsUnderMemoryPressureAreAWarning(): void
    {
        $rows = $this->rowsFor(
            $this->globalStatus(['Qcache_lowmem_prunes' => '48000']),
            $this->variables(['query_cache_type' => 'ON', 'query_cache_size' => '67108864'])
        );

        $this->assertSame('48 000', $rows['Low-memory Prunes']['value']);
        $this->assertSame(Status::WARN, $rows['Low-memory Prunes']['status']);
    }
}
