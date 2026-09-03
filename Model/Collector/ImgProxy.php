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
                . 'example ":8081" — and put the resulting /metrics address in Stores > Configuration > '
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

        $metrics = $this->prometheus->parse($body);
        if ($metrics === []) {
            return $result->setStatus(Status::UNAVAILABLE)->setSummary(
                sprintf(
                    '%s answered, but with no Prometheus samples. Check that this is the imgproxy metrics '
                    . 'endpoint and not its health check.',
                    $shownUrl
                )
            );
        }

        $requests = $this->prometheus->get($metrics, 'requests_total');
        $result->setSummary(
            $requests === null
                ? sprintf('imgproxy — %s', $shownUrl)
                : sprintf('%s requests served — %s', $this->formatter->number($requests), $shownUrl)
        );

        $this->addTrafficRows($result, $metrics);
        $this->addConcurrencyRows($result, $metrics);
        $this->addTimingRows($result, $metrics);
        $this->addRuntimeRows($result, $metrics);

        return $result;
    }

    /**
     * @param Result $result
     * @param array<string, float> $metrics
     * @return void
     */
    private function addTrafficRows(Result $result, array $metrics): void
    {
        $section = 'Traffic';
        $requests = $this->prometheus->get($metrics, 'requests_total');
        $errors = $this->prometheus->get($metrics, 'errors_total');

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
                'Failed downloads and failed conversions both land here. A rising rate usually means the '
                . 'origin the images are fetched from is unwell, not imgproxy.'
            );

            return;
        }

        $result->add($section, 'Errors', $this->formatter->number($errors), $errors > 0 ? Status::WARN : Status::OK);
    }

    /**
     * @param Result $result
     * @param array<string, float> $metrics
     * @return void
     */
    private function addConcurrencyRows(Result $result, array $metrics): void
    {
        $section = 'Concurrency';

        $inProgress = $this->prometheus->get($metrics, 'requests_in_progress');
        $images = $this->prometheus->get($metrics, 'images_in_progress');
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

        $workers = $this->prometheus->get($metrics, 'workers');
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

        $utilization = $this->prometheus->get($metrics, 'workers_utilization');
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
     * @param array<string, float> $metrics
     * @return void
     */
    private function addTimingRows(Result $result, array $metrics): void
    {
        $section = 'Timing';
        $timings = [
            'Request Time' => [
                'request_duration_seconds',
                'End to end, including fetching the source image.',
            ],
            'Download Time' => [
                'download_duration_seconds',
                'Time spent pulling the source image. High here is the origin or the network, not imgproxy.',
            ],
            'Processing Time' => [
                'processing_duration_seconds',
                'Time spent decoding and resizing. High here is CPU, or source images far larger than needed.',
            ],
        ];

        foreach ($timings as $label => [$metric, $hint]) {
            $average = $this->prometheus->average($metrics, $metric);
            if ($average === null) {
                continue;
            }

            $result->add(
                $section,
                'Average ' . $label,
                $this->formatter->seconds($average),
                Status::INFO,
                $hint . ' Averaged over every request since start, so a bad hour is invisible here.'
            );
        }
    }

    /**
     * The Go runtime numbers the Prometheus client exports for free.
     *
     * @param Result $result
     * @param array<string, float> $metrics
     * @return void
     */
    private function addRuntimeRows(Result $result, array $metrics): void
    {
        $section = 'Runtime';

        $goroutines = $this->prometheus->get($metrics, 'go_goroutines');
        if ($goroutines !== null) {
            $result->add($section, 'Goroutines', $this->formatter->number($goroutines));
        }

        $heap = $this->prometheus->get($metrics, 'go_memstats_alloc_bytes');
        if ($heap !== null) {
            $result->add(
                $section,
                'Go Heap',
                $this->formatter->bytes($heap),
                Status::INFO,
                'Live objects only. imgproxy holds decoded images outside this, so it reads far lower than '
                . 'the process memory below.'
            );
        }

        $resident = $this->prometheus->get($metrics, 'process_resident_memory_bytes');
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
