<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Standalone;

use Magenx\Platform\Model\Collector\FrankenPhp;
use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Http\PrometheusMetrics;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The rows built from a FrankenPHP scrape.
 *
 * Driven with literal exposition text through the real Prometheus reader, so
 * what is pinned is the whole path from a Caddy /metrics body to a row — and,
 * as with the FPM rows, the judgements rather than the wording.
 */
#[CoversClass(FrankenPhp::class)]
class FrankenPhpRowsTest extends TestCase
{
    /**
     * A regular-thread server with a worker beside it, Caddy http metrics on.
     */
    private const SCRAPE = <<<'PROM'
# HELP frankenphp_total_threads Total number of PHP threads
# TYPE frankenphp_total_threads gauge
frankenphp_total_threads 20
frankenphp_busy_threads 6
frankenphp_queue_depth 0
frankenphp_total_workers{worker="/app/worker.php"} 4
frankenphp_busy_workers{worker="/app/worker.php"} 1
frankenphp_ready_workers{worker="/app/worker.php"} 4
frankenphp_worker_queue_depth{worker="/app/worker.php"} 0
frankenphp_worker_request_count{worker="/app/worker.php"} 1000
frankenphp_worker_request_time{worker="/app/worker.php"} 25
frankenphp_worker_crashes{worker="/app/worker.php"} 0
frankenphp_worker_restarts{worker="/app/worker.php"} 2
frankenphp_opcache_restarts{reason="oom"} 0
frankenphp_opcache_restarts{reason="hash"} 0
frankenphp_opcache_restarts{reason="manual"} 0
caddy_http_requests_in_flight{handler="php",server="srv0"} 3
caddy_http_requests_in_flight{handler="metrics",server="srv1"} 1
caddy_http_request_duration_seconds_sum{code="200",handler="php",method="GET",server="srv0"} 180
caddy_http_request_duration_seconds_count{code="200",handler="php",method="GET",server="srv0"} 900
caddy_http_request_duration_seconds_sum{code="200",handler="php",method="POST",server="srv0"} 20
caddy_http_request_duration_seconds_count{code="200",handler="php",method="POST",server="srv0"} 90
caddy_http_request_duration_seconds_sum{code="502",handler="php",method="GET",server="srv0"} 0
caddy_http_request_duration_seconds_count{code="502",handler="php",method="GET",server="srv0"} 10
caddy_http_request_duration_seconds_sum{code="200",handler="metrics",method="GET",server="srv1"} 5
caddy_http_request_duration_seconds_count{code="200",handler="metrics",method="GET",server="srv1"} 5000
go_goroutines 42
process_resident_memory_bytes 1073741824
PROM;

    /**
     * Every row the scrape produces, keyed "Section / Label".
     *
     * @param string $body
     * @return array<string, array>
     */
    private function rowsFor(string $body): array
    {
        $reflection = new ReflectionClass(FrankenPhp::class);
        $collector = $reflection->newInstanceWithoutConstructor();

        $collaborators = [
            'formatter' => new Formatter(),
            'status' => new Status(),
            'prometheus' => new PrometheusMetrics(),
        ];
        foreach ($collaborators as $name => $collaborator) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($collector, $collaborator);
        }

        $samples = (new PrometheusMetrics())->parse($body);
        $result = new Result(new Status());

        foreach (['addThreadRows', 'addWorkerRows', 'addOpcacheRows', 'addHttpRows', 'addRuntimeRows'] as $builder) {
            $method = $reflection->getMethod($builder);
            $method->setAccessible(true);
            $method->invokeArgs($collector, [$result, $samples]);
        }

        $rows = [];
        foreach ($result->toArray()['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $rows[$section['label'] . ' / ' . $row['label']] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, string> $replacements metric line prefix => replacement line
     * @return string
     */
    private function scrapeWith(array $replacements): string
    {
        $lines = explode("\n", self::SCRAPE);
        foreach ($lines as $i => $line) {
            foreach ($replacements as $prefix => $replacement) {
                if (str_starts_with($line, $prefix . ' ')) {
                    $lines[$i] = $replacement;
                }
            }
        }

        return implode("\n", $lines);
    }

    public function testAHealthyServerIsAllGreen(): void
    {
        // The fixture's 502s are there for the status-code test; without them
        // nothing on the scrape is out of bounds.
        $healthy = implode(
            "\n",
            array_filter(explode("\n", self::SCRAPE), static fn (string $line): bool => !str_contains($line, '502'))
        );
        $rows = $this->rowsFor($healthy);

        foreach ($rows as $key => $row) {
            $this->assertContains($row['status'], [Status::OK, Status::INFO], $key);
        }
    }

    public function testWorkerThreadsAreTakenOutBeforeRating(): void
    {
        // 20 threads, 4 of them workers that always count as busy, 6 busy in
        // all: 2 of the 16 regular threads are serving a request.
        $rows = $this->rowsFor(self::SCRAPE);

        $this->assertSame('6 / 20', $rows['Threads / Busy']['value']);
        $this->assertSame(Status::INFO, $rows['Threads / Busy']['status']);
        $this->assertSame('2 / 16 (12.5%)', $rows['Threads / Regular Threads Busy']['value']);
        $this->assertSame(Status::OK, $rows['Threads / Regular Threads Busy']['status']);
    }

    public function testAWorkerOnlyServerDoesNotSitAtItsCeiling(): void
    {
        // Every thread a worker: nothing regular to rate, so no row rather than
        // a permanent 100%.
        $rows = $this->rowsFor(
            $this->scrapeWith(['frankenphp_total_threads' => 'frankenphp_total_threads 4',
                'frankenphp_busy_threads' => 'frankenphp_busy_threads 4'])
        );

        $this->assertArrayNotHasKey('Threads / Regular Threads Busy', $rows);
    }

    public function testFullRegularThreadsAreAnError(): void
    {
        $rows = $this->rowsFor($this->scrapeWith(['frankenphp_busy_threads' => 'frankenphp_busy_threads 20']));

        $this->assertSame(Status::ERROR, $rows['Threads / Regular Threads Busy']['status']);
    }

    public function testAnyQueuedRequestIsFlagged(): void
    {
        $rows = $this->rowsFor($this->scrapeWith(['frankenphp_queue_depth' => 'frankenphp_queue_depth 3']));

        $this->assertSame('3', $rows['Threads / Queue Depth']['value']);
        $this->assertSame(Status::WARN, $rows['Threads / Queue Depth']['status']);
    }

    public function testTheWorkerQueueIsNotFoldedIntoTheThreadQueue(): void
    {
        // The reason the families are read by full name: a suffix match on
        // "queue_depth" would pick up frankenphp_worker_queue_depth too.
        $rows = $this->rowsFor(
            $this->scrapeWith([
                'frankenphp_worker_queue_depth{worker="/app/worker.php"}'
                    => 'frankenphp_worker_queue_depth{worker="/app/worker.php"} 5',
            ])
        );

        $this->assertSame('0', $rows['Threads / Queue Depth']['value']);
        $this->assertSame('5', $rows['Worker /app/worker.php / Queue Depth']['value']);
        $this->assertSame(Status::WARN, $rows['Worker /app/worker.php / Queue Depth']['status']);
    }

    public function testWorkerRowsAreBuiltPerWorker(): void
    {
        $rows = $this->rowsFor(self::SCRAPE);

        $this->assertSame('4 / 4', $rows['Worker /app/worker.php / Ready']['value']);
        $this->assertSame('1 / 4 (25.0%)', $rows['Worker /app/worker.php / Busy']['value']);
        $this->assertSame('1 000', $rows['Worker /app/worker.php / Requests']['value']);
        $this->assertSame('25.0 ms', $rows['Worker /app/worker.php / Average Request Time']['value']);
        $this->assertSame(Status::INFO, $rows['Worker /app/worker.php / Restarts']['status']);
    }

    public function testAWorkerThatHasNotBootedIsFlagged(): void
    {
        $rows = $this->rowsFor(
            $this->scrapeWith([
                'frankenphp_ready_workers{worker="/app/worker.php"}'
                    => 'frankenphp_ready_workers{worker="/app/worker.php"} 3',
            ])
        );

        $this->assertSame(Status::WARN, $rows['Worker /app/worker.php / Ready']['status']);
    }

    public function testAnyWorkerCrashIsAnError(): void
    {
        $rows = $this->rowsFor(
            $this->scrapeWith([
                'frankenphp_worker_crashes{worker="/app/worker.php"}'
                    => 'frankenphp_worker_crashes{worker="/app/worker.php"} 1',
            ])
        );

        $this->assertSame(Status::ERROR, $rows['Worker /app/worker.php / Crashes']['status']);
    }

    public function testOpcacheRestartsAreRatedByReason(): void
    {
        $rows = $this->rowsFor(
            $this->scrapeWith([
                'frankenphp_opcache_restarts{reason="oom"}' => 'frankenphp_opcache_restarts{reason="oom"} 2',
                'frankenphp_opcache_restarts{reason="manual"}' => 'frankenphp_opcache_restarts{reason="manual"} 1',
            ])
        );

        $this->assertSame(Status::WARN, $rows['OPcache / Restarts (oom)']['status']);
        $this->assertSame(Status::OK, $rows['OPcache / Restarts (hash)']['status']);
        // FrankenPHP replaces opcache_reset(), so this one should be impossible.
        $this->assertSame(Status::ERROR, $rows['OPcache / Restarts (manual)']['status']);
    }

    public function testTheScrapeItselfIsNotCountedAsTraffic(): void
    {
        $rows = $this->rowsFor(self::SCRAPE);

        $this->assertSame('3', $rows['HTTP / In Flight']['value']);
        $this->assertSame('1 000', $rows['HTTP / Requests']['value']);
        $this->assertSame('200.0 ms', $rows['HTTP / Average Request Time']['value']);
    }

    public function testStatusCodesAreSplitByCodeAloneAndOnly5xxIsRated(): void
    {
        // The histogram carries method and server as well; the 200 row must
        // total both methods rather than appear once per label set.
        $rows = $this->rowsFor(self::SCRAPE);

        $this->assertSame('990 (99.00%)', $rows['Status Codes / 200']['value']);
        $this->assertSame(Status::INFO, $rows['Status Codes / 200']['status']);
        $this->assertSame('10 (1.00%)', $rows['Status Codes / 502']['value']);
        $this->assertSame(Status::WARN, $rows['Status Codes / 502']['status']);
    }

    public function testAbsentFamiliesProduceNoRows(): void
    {
        // A FrankenPHP on PHP 8.3, no workers and no "metrics" global option:
        // only the thread gauges are published.
        $rows = $this->rowsFor(
            "frankenphp_total_threads 8\nfrankenphp_busy_threads 1\nfrankenphp_queue_depth 0\n"
        );

        $this->assertSame(
            ['Threads / Busy', 'Threads / Regular Threads Busy', 'Threads / Queue Depth'],
            array_keys($rows)
        );
    }
}
