<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Standalone;

use Magenx\Platform\Model\Collector\Php;
use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The rows built from opcache_get_status().
 *
 * Same shape as the FPM row test above: the call happens in addOpcacheRows()
 * and the builders take the decoded array, so they can be driven with a literal
 * payload and no test doubles beyond this module's own formatter and severity
 * vocabulary.
 *
 * What is pinned here is which of OPcache's numbers reach the tab, and which of
 * them are measured against a limit rather than merely reported — the two
 * things that decide whether a full interned string buffer or a cache that has
 * stopped caching is visible before it is diagnosed the hard way.
 */
#[CoversClass(Php::class)]
class OpcacheRowsTest extends TestCase
{
    /** opcache.max_wasted_percentage, the PHP default, as the collector reads it. */
    private const MAX_WASTED_PCT = 5.0;

    /**
     * A healthy cache on a PHP 8 build with JIT and preloading configured,
     * carrying every block opcache_get_status() can publish.
     *
     * @param array $overrides Merged over the top level.
     * @return array
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'opcache_enabled' => true,
            'cache_full' => false,
            'restart_pending' => false,
            'restart_in_progress' => false,
            'memory_usage' => [
                'used_memory' => 402653184,
                'free_memory' => 131072000,
                'wasted_memory' => 3145728,
                'current_wasted_percentage' => 0.5859375,
            ],
            'interned_strings_usage' => [
                'buffer_size' => 33554432,
                'used_memory' => 12582912,
                'free_memory' => 20971520,
                'number_of_strings' => 186000,
            ],
            'opcache_statistics' => [
                'num_cached_scripts' => 18400,
                'num_cached_keys' => 32000,
                'max_cached_keys' => 65407,
                'hits' => 9876543,
                'start_time' => 1756900000,
                'last_restart_time' => 0,
                'oom_restarts' => 0,
                'hash_restarts' => 0,
                'manual_restarts' => 2,
                'misses' => 18400,
                'blacklist_misses' => 0,
                'blacklist_miss_ratio' => 0.0,
                'opcache_hit_rate' => 99.81400000000001,
            ],
            'jit' => [
                'enabled' => true,
                'on' => true,
                'kind' => 4,
                'opt_level' => 4,
                'opt_flags' => 6,
                'buffer_size' => 67108864,
                'buffer_free' => 50331648,
            ],
            'preload_statistics' => [
                'memory_consumption' => 41943040,
                'functions' => ['foo', 'bar'],
                'classes' => ['A', 'B', 'C'],
                'scripts' => ['/app/a.php'],
            ],
        ];
    }

    /**
     * What a PHP 7.4 build without preloading publishes: no JIT block, no
     * preload block, and no interned string counters worth speaking of.
     *
     * @return array
     */
    private function olderPayload(): array
    {
        $payload = $this->payload();
        unset($payload['jit'], $payload['preload_statistics']);

        return $payload;
    }

    /**
     * Every OPcache row the payload produces, keyed by its label.
     *
     * @param array $opcache
     * @return array<string, array>
     */
    private function rowsFor(array $opcache): array
    {
        $reflection = new ReflectionClass(Php::class);
        $collector = $reflection->newInstanceWithoutConstructor();

        foreach (['formatter' => new Formatter(), 'status' => new Status()] as $name => $collaborator) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($collector, $collaborator);
        }

        $result = new Result(new Status());
        $section = 'OPcache (this worker only)';

        $builders = [
            'addOpcacheMemoryRows' => [$result, $section, $opcache, self::MAX_WASTED_PCT],
            'addOpcacheInternedStringRows' => [$result, $section, $opcache],
            'addOpcacheScriptRows' => [$result, $section, $opcache],
            'addOpcacheRestartRows' => [$result, $section, $opcache],
            'addOpcacheJitRows' => [$result, $section, $opcache],
            'addOpcachePreloadRows' => [$result, $section, $opcache],
        ];

        foreach ($builders as $builder => $arguments) {
            $method = $reflection->getMethod($builder);
            $method->setAccessible(true);
            $method->invokeArgs($collector, $arguments);
        }

        $rows = [];
        foreach ($result->toArray()['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $rows[$row['label']] = $row;
            }
        }

        return $rows;
    }

    public function testEveryPublishedBlockReachesTheTab(): void
    {
        $rows = $this->rowsFor($this->payload());

        $this->assertSame(
            [
                'Memory',
                'Free Memory',
                'Wasted Memory',
                'Cache Full',
                'Interned Strings',
                'Interned String Count',
                'Cached Scripts',
                'Cached Keys',
                'Hit Rate',
                'Blacklist Misses',
                'Out-of-memory Restarts',
                'Hash Restarts',
                'Manual Restarts',
                'Restart',
                'Cache Started',
                'Last Restart',
                'JIT',
                'JIT Buffer',
                'Preloaded',
                'Preload Memory',
            ],
            array_keys($rows)
        );
    }

    public function testABuildWithoutJitOrPreloadingOmitsThoseRowsRatherThanReportingZero(): void
    {
        $rows = $this->rowsFor($this->olderPayload());

        $this->assertArrayNotHasKey('JIT', $rows);
        $this->assertArrayNotHasKey('JIT Buffer', $rows);
        $this->assertArrayNotHasKey('Preloaded', $rows);
        $this->assertArrayNotHasKey('Preload Memory', $rows);
        // The blocks every build publishes are still there.
        $this->assertArrayHasKey('Memory', $rows);
        $this->assertArrayHasKey('Interned Strings', $rows);
    }

    public function testMemoryIsTheUsedFreeAndWastedTotal(): void
    {
        $rows = $this->rowsFor($this->payload());

        // 384 MB used of the 512 MB the three counters add up to.
        $this->assertSame('384.0 MB / 512.0 MB (75.0%)', $rows['Memory']['value']);
        $this->assertSame(Status::OK, $rows['Memory']['status']);
        $this->assertSame('125.0 MB', $rows['Free Memory']['value']);
    }

    public function testWastedMemoryIsMeasuredAgainstTheRestartThresholdNotAgainstZero(): void
    {
        $rows = $this->rowsFor($this->payload());

        // Waste is normal; it only matters as it approaches the percentage
        // OPcache throws the whole cache away at.
        $this->assertSame('3.0 MB (0.59%, restarts at 5%)', $rows['Wasted Memory']['value']);
        $this->assertSame(Status::OK, $rows['Wasted Memory']['status']);

        $atThreshold = $this->payload();
        $atThreshold['memory_usage']['current_wasted_percentage'] = 5.0;

        $this->assertSame(Status::WARN, $this->rowsFor($atThreshold)['Wasted Memory']['status']);
    }

    public function testACacheWithNowhereLeftToPutAScriptIsAnError(): void
    {
        $rows = $this->rowsFor(['cache_full' => true] + $this->payload());

        $this->assertSame('Yes', $rows['Cache Full']['value']);
        $this->assertSame(Status::ERROR, $rows['Cache Full']['status']);
    }

    public function testTheInternedStringBufferIsMeasuredAndNotOnlyCounted(): void
    {
        $rows = $this->rowsFor($this->payload());

        $this->assertSame('12.0 MB / 32.0 MB (37.5%)', $rows['Interned Strings']['value']);
        $this->assertSame(Status::OK, $rows['Interned Strings']['status']);
        $this->assertSame('186 000', $rows['Interned String Count']['value']);

        $full = $this->payload();
        $full['interned_strings_usage']['used_memory'] = 32505856;
        $full['interned_strings_usage']['free_memory'] = 1048576;

        $this->assertSame(Status::ERROR, $this->rowsFor($full)['Interned Strings']['status']);
    }

    public function testHitRateCarriesTheCountersItWasComputedFrom(): void
    {
        $rows = $this->rowsFor($this->payload());

        $this->assertSame('99.81% (9 876 543 hits, 18 400 misses)', $rows['Hit Rate']['value']);
    }

    public function testHitRateIsComputedWhenTheBuildDoesNotPublishIt(): void
    {
        $payload = $this->payload();
        unset($payload['opcache_statistics']['opcache_hit_rate']);
        $payload['opcache_statistics']['hits'] = 3;
        $payload['opcache_statistics']['misses'] = 1;

        $this->assertSame('75.00% (3 hits, 1 misses)', $this->rowsFor($payload)['Hit Rate']['value']);
    }

    public function testKeysAndScriptsAreSeparateNumbers(): void
    {
        $rows = $this->rowsFor($this->payload());

        // A key is spent per path a script is reached by, so the two counts
        // differ and only the keys are measured against a directive.
        $this->assertSame('18 400', $rows['Cached Scripts']['value']);
        $this->assertSame('32 000 / 65 407 (48.9%)', $rows['Cached Keys']['value']);
        $this->assertSame(Status::OK, $rows['Cached Keys']['status']);
    }

    public function testTheThreeRestartCausesAreCountedSeparately(): void
    {
        $payload = $this->payload();
        $payload['opcache_statistics']['oom_restarts'] = 3;
        $payload['opcache_statistics']['hash_restarts'] = 1;
        $payload['opcache_statistics']['manual_restarts'] = 12;

        $rows = $this->rowsFor($payload);

        // Memory too small, key table too small, and somebody ran a deploy —
        // three different faults that must not roll into one number.
        $this->assertSame(Status::WARN, $rows['Out-of-memory Restarts']['status']);
        $this->assertSame(Status::WARN, $rows['Hash Restarts']['status']);
        $this->assertSame(Status::INFO, $rows['Manual Restarts']['status']);
        $this->assertSame('12', $rows['Manual Restarts']['value']);
    }

    public function testARestartInFlightIsAWarning(): void
    {
        $this->assertSame('No', $this->rowsFor($this->payload())['Restart']['value']);
        $this->assertSame(Status::OK, $this->rowsFor($this->payload())['Restart']['status']);

        $pending = $this->rowsFor(['restart_pending' => true] + $this->payload())['Restart'];
        $this->assertSame('Pending', $pending['value']);
        $this->assertSame(Status::WARN, $pending['status']);

        $running = $this->rowsFor(
            ['restart_pending' => true, 'restart_in_progress' => true] + $this->payload()
        )['Restart'];
        $this->assertSame('In progress', $running['value']);
        $this->assertSame(Status::WARN, $running['status']);
    }

    public function testTheCacheTimestampsArePrintedInUtcAndNeverAsEpochZero(): void
    {
        $payload = $this->payload();
        $payload['opcache_statistics']['start_time'] = time() - 7200;

        $rows = $this->rowsFor($payload);

        $this->assertStringContainsString(' UTC (2h 0m ago)', $rows['Cache Started']['value']);
        // A cache that has never restarted reports 0, which must not be printed
        // as 1970.
        $this->assertSame('Never', $rows['Last Restart']['value']);

        $payload['opcache_statistics']['last_restart_time'] = 1756900000;
        $this->assertStringStartsWith(
            '2025-09-03 11:46:40 UTC',
            $this->rowsFor($payload)['Last Restart']['value']
        );
    }

    public function testJitIsReportedWithItsBufferAndNeverJudged(): void
    {
        $rows = $this->rowsFor($this->payload());

        $this->assertSame('On (kind 4, opt level 4)', $rows['JIT']['value']);
        $this->assertSame(Status::INFO, $rows['JIT']['status']);
        $this->assertSame('16.0 MB / 64.0 MB (25.0%)', $rows['JIT Buffer']['value']);

        $off = $this->payload();
        $off['jit'] = ['enabled' => false, 'on' => false, 'buffer_size' => 0, 'buffer_free' => 0];
        $rows = $this->rowsFor($off);

        $this->assertSame('Not enabled', $rows['JIT']['value']);
        $this->assertSame(Status::INFO, $rows['JIT']['status']);
        $this->assertArrayNotHasKey('JIT Buffer', $rows);
    }

    public function testPreloadingIsSummarizedFromTheListsOpcachePublishes(): void
    {
        $rows = $this->rowsFor($this->payload());

        // The three keys are lists of names, not counts.
        $this->assertSame('1 scripts, 2 functions, 3 classes', $rows['Preloaded']['value']);
        $this->assertSame('40.0 MB', $rows['Preload Memory']['value']);
    }
}
