<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Collector;

use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Http\PrometheusMetrics;
use Magenx\Platform\Model\Http\StatusFetcher;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\ResultFactory;
use Magenx\Platform\Model\Metric\Status;

/**
 * imgproxy throughput and saturation, from its Prometheus endpoint.
 *
 * imgproxy publishes numbers two ways and only one of them is readable from
 * here. IMGPROXY_OPEN_TELEMETRY_ENABLE_METRICS *pushes* OTLP to a collector and
 * exposes no endpoint at all, so consuming it would mean running an OpenTelemetry
 * Collector and scraping that instead — a pipeline, not a tab. IMGPROXY_PROMETHEUS_BIND
 * serves plain text over HTTP, which is what this collector reads.
 *
 * The line that matters most here is workers against workers utilization: an
 * imgproxy at its concurrency limit queues requests, and the storefront sees
 * that as slow images long before anything else in the stack looks unwell.
 */
class ImgProxy implements CollectorInterface
{
    /** A handful of failed conversions is normal; a percent of them is not. */
    private const ERROR_RATE_WARN_PCT = 1.0;
    private const ERROR_RATE_ERROR_PCT = 5.0;

    private const WORKERS_WARN_PCT = 80.0;
    private const WORKERS_ERROR_PCT = 95.0;

    private StatusFetcher $fetcher;

    private PrometheusMetrics $prometheus;

    private Config $config;

    private ResultFactory $resultFactory;

    private Formatter $formatter;

    private Status $status;

    /**
     * @param StatusFetcher $fetcher
     * @param PrometheusMetrics $prometheus
     * @param Config $config
     * @param ResultFactory $resultFactory
     * @param Formatter $formatter
     * @param Status $status
     */
    public function __construct(
        StatusFetcher $fetcher,
        PrometheusMetrics $prometheus,
        Config $config,
        ResultFactory $resultFactory,
        Formatter $formatter,
        Status $status
    ) {
        $this->fetcher = $fetcher;
        $this->prometheus = $prometheus;
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
        return 'imgproxy';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        /** @var Result $result */
        $result = $this->resultFactory->create();

        $url = $this->config->getImgProxyMetricsUrl();
        if ($url === '') {
            return $result->setStatus(Status::UNAVAILABLE)->setSummary(
                'No metrics URL is configured. Start imgproxy with IMGPROXY_PROMETHEUS_BIND set — for '
                . 'example ":4594" — and put the resulting address in Stores > Configuration > '
                . 'Magenx > Platform Overview.'
            );
        }

        // Redacted for display: the URL is admin-entered and may carry userinfo.
        $shownUrl = $this->fetcher->redact($url);

        $body = $this->fetcher->fetch($url);
        if ($body === null) {
            return $result->setStatus(Status::UNAVAILABLE)
                ->setSummary(sprintf('%s did not answer (%s).', $shownUrl, $this->fetcher->getLastError()));
        }

        $samples = $this->prometheus->parse($body);
        if ($samples === []) {
            return $result->setStatus(Status::UNAVAILABLE)->setSummary(
                sprintf(
                    '%s answered, but with no Prometheus samples. Check that this is the imgproxy metrics '
                    . 'endpoint and not its health check.',
                    $shownUrl
                )
            );
        }

        $requests = $this->prometheus->sum($samples, 'requests_total');
        $result->setSummary(
            $requests === null
                ? sprintf('imgproxy — %s', $shownUrl)
                : sprintf('%s requests served — %s', $this->formatter->number($requests), $shownUrl)
        );

        $this->addTrafficRows($result, $samples);
        $this->addStatusCodeRows($result, $samples);
        $this->addConcurrencyRows($result, $samples);
        $this->addTimingRows($result, $samples);
        $this->addVipsRows($result, $samples);
        $this->addRuntimeRows($result, $samples);

        return $result;
    }

    /**
     * @param Result $result
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @return void
     */
    private function addTrafficRows(Result $result, array $samples): void
    {
        $section = 'Traffic';
        $requests = $this->prometheus->sum($samples, 'requests_total');
        // sum(), not a first-match lookup: imgproxy splits errors_total by type,
        // so reading a single sample would report one type's count as the total.
        $errors = $this->prometheus->sum($samples, 'errors_total');

        if ($requests !== null) {
            $result->add(
                $section,
                'Requests',
                $this->formatter->number($requests),
                Status::INFO,
                'Since imgproxy last started. Counters reset with the container.'
            );
        }

        if ($errors === null) {
            return;
        }

        // Rated against requests rather than shown bare: a thousand errors is
        // either catastrophic or a rounding error depending on the denominator.
        if ($requests !== null && $requests > 0) {
            $errorPct = $this->formatter->ratio($errors, $requests);
            $result->add(
                $section,
                'Errors',
                sprintf('%s (%s)', $this->formatter->number($errors), $this->formatter->percent($errorPct, 2)),
                $this->status->forCeiling($errorPct, self::ERROR_RATE_WARN_PCT, self::ERROR_RATE_ERROR_PCT),
                'Every error type totalled. The split below says which.'
            );
        } else {
            $result->add(
                $section,
                'Errors',
                $this->formatter->number($errors),
                $errors > 0 ? Status::WARN : Status::OK
            );
        }

        $this->addBreakdownRows(
            $result,
            $section,
            $this->prometheus->breakdown($samples, 'errors_total'),
            $requests,
            'downloading blames the origin the images come from, processing blames the image itself, '
            . 'and timeout can be either.'
        );
    }

    /**
     * One row per label value of a split counter.
     *
     * Always INFO. These are cumulative since imgproxy started, so any long-lived
     * instance has a non-zero count in most of them — a row that is permanently
     * amber is a row people learn to skip. The totalled row above carries the
     * severity, measured as a rate.
     *
     * @param Result $result
     * @param string $section
     * @param array<int, array{label: string, value: float}> $breakdown
     * @param float|null $requests
     * @param string $hint
     * @return void
     */
    private function addBreakdownRows(
        Result $result,
        string $section,
        array $breakdown,
        ?float $requests,
        string $hint
    ): void {
        foreach ($breakdown as ['label' => $label, 'value' => $count]) {
            if ($label === '') {
                continue;
            }
            $result->add(
                $section,
                $label,
                $requests !== null && $requests > 0
                    ? sprintf(
                        '%s (%s)',
                        $this->formatter->number($count),
                        $this->formatter->percent($this->formatter->ratio($count, $requests), 2)
                    )
                    : $this->formatter->number($count),
                Status::INFO,
                $hint
            );
        }
    }

    /**
     * Responses by status code.
     *
     * Only 5xx is rated: a 4xx on imgproxy is usually a URL that was mistyped or
     * signed wrong, which is the caller's problem and not a fault of the stack.
     *
     * @param Result $result
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @return void
     */
    private function addStatusCodeRows(Result $result, array $samples): void
    {
        $codes = $this->prometheus->breakdown($samples, 'status_codes_total');
        if ($codes === []) {
            return;
        }

        $section = 'Status Codes';
        $requests = $this->prometheus->sum($samples, 'requests_total');

        foreach ($codes as ['label' => $code, 'value' => $count]) {
            if ($code === '') {
                continue;
            }

            $share = $requests !== null && $requests > 0 ? $this->formatter->ratio($count, $requests) : null;
            $isServerError = (int) $code >= 500 && (int) $code < 600;

            $result->add(
                $section,
                $code,
                $share === null
                    ? $this->formatter->number($count)
                    : sprintf('%s (%s)', $this->formatter->number($count), $this->formatter->percent($share, 2)),
                $isServerError && $share !== null
                    ? $this->status->forCeiling($share, self::ERROR_RATE_WARN_PCT, self::ERROR_RATE_ERROR_PCT)
                    : Status::INFO,
                $isServerError
                    ? 'imgproxy could not produce the image. Rated against total requests, because a handful '
                    . 'since the container started is not the same as a steady trickle.'
                    : 'Counted since imgproxy started.'
            );
        }
    }

    /**
     * @param Result $result
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @return void
     */
    private function addConcurrencyRows(Result $result, array $samples): void
    {
        $section = 'Concurrency';

        $inProgress = $this->prometheus->sum($samples, 'requests_in_progress');
        $images = $this->prometheus->sum($samples, 'images_in_progress');
        if ($inProgress !== null || $images !== null) {
            $result->add(
                $section,
                'In Progress',
                sprintf(
                    '%s requests, %s images',
                    $this->formatter->number($inProgress ?? 0),
                    $this->formatter->number($images ?? 0)
                )
            );
        }

        $workers = $this->prometheus->sum($samples, 'workers');
        if ($workers !== null) {
            $result->add(
                $section,
                'Workers',
                $this->formatter->number($workers),
                Status::INFO,
                'IMGPROXY_WORKERS. Each one holds a decoded image in memory, so raising it trades RAM for '
                . 'concurrency.'
            );
        }

        $utilization = $this->prometheus->sum($samples, 'workers_utilization');
        if ($utilization !== null) {
            $result->add(
                $section,
                'Worker Utilization',
                $this->formatter->percent($utilization),
                $this->status->forCeiling($utilization, self::WORKERS_WARN_PCT, self::WORKERS_ERROR_PCT),
                'At the ceiling imgproxy queues rather than refuses, and the storefront sees it as slow '
                . 'images with nothing else in the stack looking unwell.'
            );
        }
    }

    /**
     * @param Result $result
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @return void
     */
    private function addTimingRows(Result $result, array $samples): void
    {
        $section = 'Timing';
        $caveat = ' Averaged over every request since start, so a bad hour is invisible here.';

        $overall = $this->prometheus->average($samples, 'request_duration_seconds');
        if ($overall !== null) {
            $result->add(
                $section,
                'Average Request Time',
                $this->formatter->seconds($overall),
                Status::INFO,
                'End to end, including waiting for a worker and fetching the source image.' . $caveat
            );
        }

        // imgproxy publishes one histogram split by span rather than a metric
        // per phase, so these are the same family filtered by label value.
        $spans = [
            'Queue Time' => [
                'queue',
                'Time spent waiting for a free worker before any work started. Anything but negligible '
                . 'here is saturation, and it is what corroborates the worker utilization above.',
            ],
            'Download Time' => [
                'downloading',
                'Time spent pulling the source image. High here is the origin or the network, not imgproxy.',
            ],
            'Processing Time' => [
                'processing',
                'Time spent decoding and resizing. High here is CPU, or source images far larger than needed.',
            ],
        ];

        foreach ($spans as $label => [$span, $hint]) {
            $average = $this->prometheus->average($samples, 'request_span_duration_seconds', $span);
            if ($average === null) {
                continue;
            }

            $result->add(
                $section,
                'Average ' . $label,
                $this->formatter->seconds($average),
                Status::INFO,
                $hint . $caveat
            );
        }
    }

    /**
     * libvips memory, which is where imgproxy actually spends it.
     *
     * There is no limit metric to rate these against — imgproxy reports what it
     * is using, not what it is allowed — so both rows are INFO and the hints say
     * what to compare them with.
     *
     * @param Result $result
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @return void
     */
    private function addVipsRows(Result $result, array $samples): void
    {
        $section = 'libvips';

        $memory = $this->prometheus->sum($samples, 'vips_memory_bytes');
        if ($memory !== null) {
            $peak = $this->prometheus->sum($samples, 'vips_max_memory_bytes');
            $result->add(
                $section,
                'Memory',
                $peak === null
                    ? $this->formatter->bytes($memory)
                    : sprintf('%s (peak %s)', $this->formatter->bytes($memory), $this->formatter->bytes($peak)),
                Status::INFO,
                'Decoded images live here, outside the Go heap — so this, not the Go heap below, is what '
                . 'runs an imgproxy container out of memory. Compare the peak against the container limit.'
            );
        }

        $allocs = $this->prometheus->sum($samples, 'vips_allocs');
        if ($allocs !== null) {
            $result->add(
                $section,
                'Allocations',
                $this->formatter->number($allocs),
                Status::INFO,
                'Active libvips allocations. A count that climbs and never falls back is a leak.'
            );
        }
    }

    /**
     * The Go runtime numbers the Prometheus client exports for free.
     *
     * @param Result $result
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @return void
     */
    private function addRuntimeRows(Result $result, array $samples): void
    {
        $section = 'Runtime';

        $goroutines = $this->prometheus->sum($samples, 'go_goroutines');
        if ($goroutines !== null) {
            $result->add($section, 'Goroutines', $this->formatter->number($goroutines));
        }

        $heap = $this->prometheus->sum($samples, 'go_memstats_alloc_bytes');
        if ($heap !== null) {
            $result->add(
                $section,
                'Go Heap',
                $this->formatter->bytes($heap),
                Status::INFO,
                'Live Go objects only. Decoded images sit in libvips above, which is why this reads far '
                . 'lower than the resident memory below.'
            );
        }

        $resident = $this->prometheus->sum($samples, 'process_resident_memory_bytes');
        if ($resident !== null) {
            $result->add(
                $section,
                'Resident Memory',
                $this->formatter->bytes($resident),
                Status::INFO,
                'What the container is actually charged for. Compare it against the imgproxy memory limit.'
            );
        }
    }
}
