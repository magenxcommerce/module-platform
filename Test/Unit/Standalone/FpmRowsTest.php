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
 * The rows built from the php-fpm status page.
 *
 * These four builders take the decoded payload and nothing else — the fetch and
 * the JSON decode happen in addFpmRows() above them — so they can be driven
 * with a literal payload and no test doubles at all. Only the formatter and the
 * severity vocabulary are put in place, and both are this module's own concrete
 * classes.
 *
 * What is pinned here is not the wording but the judgements: which field is
 * merely reported, which one is measured against a limit, and which limit.
 */
#[CoversClass(Php::class)]
class FpmRowsTest extends TestCase
{
    /**
     * A healthy pool, carrying every field php-fpm publishes.
     *
     * @param array $overrides
     * @return array
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'pool' => 'www',
            'process manager' => 'dynamic',
            'start time' => 1756900000,
            'start since' => 90000,
            'accepted conn' => 180000,
            'listen queue' => 0,
            'max listen queue' => 0,
            'listen queue len' => 511,
            'idle processes' => 8,
            'active processes' => 2,
            'total processes' => 10,
            'max active processes' => 6,
            'max children reached' => 0,
            'slow requests' => 0,
            'memory peak' => 268435456,
        ];
    }

    /**
     * Every FPM row the payload produces, keyed by its label.
     *
     * @param array $fpm
     * @return array<string, array>
     */
    private function rowsFor(array $fpm): array
    {
        $reflection = new ReflectionClass(Php::class);
        $collector = $reflection->newInstanceWithoutConstructor();

        foreach (['formatter' => new Formatter(), 'status' => new Status()] as $name => $collaborator) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($collector, $collaborator);
        }

        $result = new Result(new Status());

        foreach (['addFpmUptimeRow', 'addFpmProcessRows', 'addFpmQueueRows', 'addFpmWorkRows'] as $builder) {
            $method = $reflection->getMethod($builder);
            $method->setAccessible(true);
            $method->invokeArgs($collector, [$result, 'FPM Pool', $fpm]);
        }

        $rows = [];
        foreach ($result->toArray()['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $rows[$row['label']] = $row;
            }
        }

        return $rows;
    }

    public function testEveryPublishedFieldReachesTheTab(): void
    {
        $rows = $this->rowsFor($this->payload());

        $this->assertSame(
            [
                'Started',
                'Processes',
                'Max Active Processes',
                'Listen Queue',
                'Max Listen Queue',
                'Listen Queue Length',
                'Max Children Reached',
                'Slow Requests',
                'Accepted Connections',
                'Memory Peak',
            ],
            array_keys($rows)
        );
    }

    public function testTheStartTimestampIsPrintedInUtcWithTheUptimeBesideIt(): void
    {
        // The status page reports the start time as a Unix timestamp and the
        // uptime as seconds; the row must not read one as the other.
        $rows = $this->rowsFor($this->payload());

        $this->assertSame('2025-09-03 11:46:40 UTC (up 1d 1h)', $rows['Started']['value']);
    }

    public function testAnAlreadyFormattedStartTimeIsPassedThrough(): void
    {
        // The HTML flavour of the status page formats the date itself. It is
        // not the flavour this collector asks for, but a proxy in front of the
        // endpoint can answer with it.
        $rows = $this->rowsFor($this->payload(['start time' => '03/Sep/2025:11:46:40 +0000']));

        $this->assertStringStartsWith('03/Sep/2025:11:46:40 +0000 (up ', $rows['Started']['value']);
    }

    public function testAPoolWithNoIdleWorkerLeftIsFlagged(): void
    {
        // The moment before "max children reached" starts counting.
        $rows = $this->rowsFor(
            $this->payload(['idle processes' => 0, 'active processes' => 10, 'total processes' => 10])
        );

        $this->assertSame(Status::WARN, $rows['Processes']['status']);
    }

    public function testALoneBusyWorkerIsNotMistakenForSaturation(): void
    {
        // An ondemand pool serving nothing but this very status request. Zero
        // idle workers out of one is an idle pool, not a full one.
        $rows = $this->rowsFor(
            $this->payload(
                [
                    'process manager' => 'ondemand',
                    'idle processes' => 0,
                    'active processes' => 1,
                    'total processes' => 1,
                ]
            )
        );

        $this->assertSame(Status::OK, $rows['Processes']['status']);
    }

    public function testAQueueThatHasTouchedTheBacklogIsFlagged(): void
    {
        // Connections arriving past this point were refused by the kernel, and
        // nginx reported them as 502s with no hint of where they came from.
        $rows = $this->rowsFor($this->payload(['max listen queue' => 511, 'listen queue len' => 511]));

        $this->assertSame(Status::WARN, $rows['Max Listen Queue']['status']);
    }

    public function testAQueueThatHasOnlyEverBackedUpIsReportedWithoutAlarm(): void
    {
        // A backlog high-water mark well under the socket's capacity is history,
        // not an incident: the queue drained.
        $rows = $this->rowsFor($this->payload(['max listen queue' => 3]));

        $this->assertSame(Status::INFO, $rows['Max Listen Queue']['status']);
        // The live queue is the one that says something about right now.
        $this->assertSame(Status::OK, $rows['Listen Queue']['status']);
    }

    public function testARequestWaitingRightNowIsFlagged(): void
    {
        $rows = $this->rowsFor($this->payload(['listen queue' => 4]));

        $this->assertSame(Status::WARN, $rows['Listen Queue']['status']);
    }

    public function testAcceptedConnectionsCarryTheirAverageRate(): void
    {
        $rows = $this->rowsFor($this->payload());

        $this->assertSame('180 000 (2.0/s)', $rows['Accepted Connections']['value']);
    }

    public function testFieldsOlderPhpVersionsDoNotPublishAreLeftOutRatherThanShownAsZero(): void
    {
        $fpm = $this->payload();
        // "memory peak" and "max active processes" are not in every FPM build,
        // and neither is a socket backlog on a pool listening on TCP with the
        // default. A zero row would read as a measurement.
        unset($fpm['memory peak'], $fpm['max active processes'], $fpm['listen queue len']);

        $rows = $this->rowsFor($fpm);

        $this->assertArrayNotHasKey('Memory Peak', $rows);
        $this->assertArrayNotHasKey('Max Active Processes', $rows);
        $this->assertArrayNotHasKey('Listen Queue Length', $rows);
        // The rows that are always published still arrive.
        $this->assertArrayHasKey('Listen Queue', $rows);
        $this->assertArrayHasKey('Max Listen Queue', $rows);
        $this->assertSame(Status::INFO, $rows['Max Listen Queue']['status']);
    }

    public function testAStatusPageWithoutTimingsSkipsTheUptimeRowEntirely(): void
    {
        $fpm = $this->payload();
        unset($fpm['start time'], $fpm['start since']);

        $rows = $this->rowsFor($fpm);

        $this->assertArrayNotHasKey('Started', $rows);
        // Without an uptime there is no denominator, so the count stands alone.
        $this->assertSame('180 000', $rows['Accepted Connections']['value']);
    }
}
