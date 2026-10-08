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
 * FrankenPHP thread and worker saturation, from Caddy's Prometheus registry.
 *
 * FrankenPHP registers its frankenphp_* families in the same registry Caddy
 * publishes its own http metrics from, so one scrape carries both. That registry
 * is served by Caddy's admin API at /metrics, but it is also served by the
 * `metrics` handler from any site block — and that is what this collector is
 * meant to read. The admin API has no read-only mode: whoever can reach it can
 * POST /load and replace the server's configuration. A site block that does
 * nothing but `metrics` publishes the same numbers and nothing else, so the
 * admin API can stay on its default loopback address.
 *
 * The line that matters most is the queue: frankenphp_queue_depth counts
 * requests waiting for a free PHP thread right now, which is the FrankenPHP
 * equivalent of php-fpm's listen queue — the storefront sees it as latency
 * before anything else in the stack looks unwell.
 */
class FrankenPhp implements CollectorInterface
{
    private const THREADS_WARN_PCT = 80.0;
    private const THREADS_ERROR_PCT = 95.0;

    /** Same thresholds as imgproxy: a handful of 5xx is normal; a percent is not. */
    private const ERROR_RATE_WARN_PCT = 1.0;
    private const ERROR_RATE_ERROR_PCT = 5.0;

    /**
     * Caddy labels http metrics with the handler that served the request, and
     * the scrape itself is served by the metrics handler. Counting it would put
     * this module's own probe into the traffic it is reporting on.
     */
    private const SCRAPE_HANDLER = 'metrics';

    /**
     * Per-worker families, keyed by the row each one feeds.
     *
     * Read by full name rather than through PrometheusMetrics' suffix match:
     * "queue_depth" alone would also match frankenphp_worker_queue_depth and
     * fold every worker's queue into the regular-thread one.
     */
    private const WORKER_FAMILIES = [
        'total' => 'frankenphp_total_workers',
        'busy' => 'frankenphp_busy_workers',
        'ready' => 'frankenphp_ready_workers',
        'queue' => 'frankenphp_worker_queue_depth',
        'requests' => 'frankenphp_worker_request_count',
        'time' => 'frankenphp_worker_request_time',
        'crashes' => 'frankenphp_worker_crashes',
        'restarts' => 'frankenphp_worker_restarts',
    ];

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
        return 'FrankenPHP';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        /** @var Result $result */
        $result = $this->resultFactory->create();

        $url = $this->config->getFrankenPhpMetricsUrl();
        if ($url === '') {
            return $result->setStatus(Status::UNAVAILABLE)->setSummary(
                'No metrics URL is configured. Add a Caddy site block that runs the "metrics" handler — for '
                . 'example ":2020 { metrics /metrics }" — and put its address in Stores > Configuration > '
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
        $threads = $this->prometheus->sum($samples, 'frankenphp_total_threads');
        if ($threads === null) {
            return $result->setStatus(Status::UNAVAILABLE)->setSummary(
                sprintf(
                    '%s answered, but with no frankenphp_ metrics. Check that this is the Caddy server running '
                    . 'FrankenPHP and that the "metrics" global option is set.',
                    $shownUrl
                )
            );
        }

        $busy = $this->prometheus->sum($samples, 'frankenphp_busy_threads') ?? 0.0;
        $result->setSummary(
            sprintf(
                '%s/%s threads busy — %s',
                $this->formatter->number($busy),
                $this->formatter->number($threads),
                $shownUrl
            )
        );

        $this->addThreadRows($result, $samples);
        $this->addWorkerRows($result, $samples);
        $this->addOpcacheRows($result, $samples);
        $this->addHttpRows($result, $samples);
        $this->addRuntimeRows($result, $samples);

        return $result;
    }

    /**
     * @param Result $result
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @return void
     */
    private function addThreadRows(Result $result, array $samples): void
    {
        $section = 'Threads';

        $total = $this->prometheus->sum($samples, 'frankenphp_total_threads');
        if ($total === null) {
            return;
        }
        $busy = $this->prometheus->sum($samples, 'frankenphp_busy_threads') ?? 0.0;
        $workerThreads = $this->prometheus->sum($samples, 'frankenphp_total_workers') ?? 0.0;

        $result->add(
            $section,
            'Busy',
            sprintf('%s / %s', $this->formatter->number($busy), $this->formatter->number($total)),
            Status::INFO,
            'Every PHP thread, worker threads included. A running worker always counts as busy, so this '
            . 'is not rated — the row below is.'
        );

        // Worker threads are taken out before rating: they count as busy for as
        // long as their script runs, so a worker-mode server would otherwise sit
        // permanently at its ceiling while serving nothing at all.
        $regularTotal = $total - $workerThreads;
        if ($regularTotal > 0) {
            $regularBusy = max(0.0, $busy - $workerThreads);
            $pct = $this->formatter->ratio($regularBusy, $regularTotal);
            $result->add(
                $section,
                'Regular Threads Busy',
                sprintf(
                    '%s / %s (%s)',
                    $this->formatter->number($regularBusy),
                    $this->formatter->number($regularTotal),
                    $this->formatter->percent($pct)
                ),
                $this->status->forCeiling($pct, self::THREADS_WARN_PCT, self::THREADS_ERROR_PCT),
                'Threads serving ordinary requests, which is how Magento runs. A snapshot of this instant, '
                . 'not an average — the queue below is what says whether it has been full long enough to hurt.'
            );
        }

        $queue = $this->prometheus->sum($samples, 'frankenphp_queue_depth');
        if ($queue !== null) {
            $result->add(
                $section,
                'Queue Depth',
                $this->formatter->number($queue),
                $queue > 0 ? Status::WARN : Status::OK,
                'Requests waiting for a free PHP thread right now. Anything above zero means every thread was '
                . 'taken — raise num_threads, or max_threads to let FrankenPHP scale them itself.'
            );
        }
    }

    /**
     * One section per worker script.
     *
     * Magento does not run in worker mode, so on this stack the section is
     * usually absent; it is here for the deployments that put a worker script
     * beside it.
     *
     * @param Result $result
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @return void
     */
    private function addWorkerRows(Result $result, array $samples): void
    {
        $workers = [];
        foreach (self::WORKER_FAMILIES as $key => $family) {
            foreach ($this->split($samples, $family, 'worker') as ['label' => $name, 'value' => $value]) {
                $workers[$name][$key] = $value;
            }
        }

        foreach ($workers as $name => $values) {
            // (string) undoes PHP's numeric-key cast; see PrometheusMetrics::breakdown().
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            $section = sprintf('Worker %s', $name);
            $total = $values['total'] ?? 0.0;

            if (isset($values['ready'])) {
                $result->add(
                    $section,
                    'Ready',
                    sprintf('%s / %s', $this->formatter->number($values['ready']), $this->formatter->number($total)),
                    $values['ready'] < $total ? Status::WARN : Status::OK,
                    'Workers that have reached frankenphp_handle_request(). Fewer than started means some are '
                    . 'still booting or failing to boot.'
                );
            }

            if (isset($values['busy']) && $total > 0) {
                $pct = $this->formatter->ratio($values['busy'], $total);
                $result->add(
                    $section,
                    'Busy',
                    sprintf(
                        '%s / %s (%s)',
                        $this->formatter->number($values['busy']),
                        $this->formatter->number($total),
                        $this->formatter->percent($pct)
                    ),
                    $this->status->forCeiling($pct, self::THREADS_WARN_PCT, self::THREADS_ERROR_PCT)
                );
            }

            if (isset($values['queue'])) {
                $result->add(
                    $section,
                    'Queue Depth',
                    $this->formatter->number($values['queue']),
                    $values['queue'] > 0 ? Status::WARN : Status::OK,
                    'Requests waiting for this worker. Above zero means every instance of it was busy.'
                );
            }

            if (isset($values['requests'])) {
                $result->add($section, 'Requests', $this->formatter->number($values['requests']));
            }

            if (isset($values['time'], $values['requests']) && $values['requests'] > 0) {
                $result->add(
                    $section,
                    'Average Request Time',
                    $this->formatter->seconds($values['time'] / $values['requests']),
                    Status::INFO,
                    'Averaged over every request since start, so a bad hour is invisible here.'
                );
            }

            if (isset($values['crashes'])) {
                $result->add(
                    $section,
                    'Crashes',
                    $this->formatter->number($values['crashes']),
                    $values['crashes'] > 0 ? Status::ERROR : Status::OK,
                    'The worker script exited when it was not asked to. FrankenPHP restarts it, so the only '
                    . 'trace is this counter and the container log.'
                );
            }

            if (isset($values['restarts'])) {
                $result->add(
                    $section,
                    'Restarts',
                    $this->formatter->number($values['restarts']),
                    Status::INFO,
                    'Deliberate restarts — max_requests reached, a watched file changed, or an admin asked.'
                );
            }
        }
    }

    /**
     * OPcache shared-memory restarts, which FrankenPHP counts and php-fpm does not.
     *
     * Published only on PHP 8.4 and later, so the section is absent below that.
     *
     * @param Result $result
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @return void
     */
    private function addOpcacheRows(Result $result, array $samples): void
    {
        $hints = [
            'oom' => 'OPcache ran out of memory and flushed itself. Raise opcache.memory_consumption.',
            'hash' => 'The script table filled up and OPcache flushed itself. Raise opcache.max_accelerated_files.',
            'manual' => 'Should never happen: FrankenPHP replaces opcache_reset(), so a manual restart means '
                . 'something bypassed it.',
        ];

        $restarts = $this->split($samples, 'frankenphp_opcache_restarts', 'reason');
        foreach ($restarts as ['label' => $reason, 'value' => $count]) {
            if ($reason === '') {
                continue;
            }

            if ($count <= 0) {
                $status = Status::OK;
            } else {
                $status = $reason === 'manual' ? Status::ERROR : Status::WARN;
            }

            $result->add(
                'OPcache',
                sprintf('Restarts (%s)', $reason),
                $this->formatter->number($count),
                $status,
                $hints[$reason] ?? 'Counted since FrankenPHP started. It should stay at zero.'
            );
        }
    }

    /**
     * Caddy's own request metrics, from the same scrape.
     *
     * Present only when the "metrics" global option is set. Read from the
     * request duration histogram rather than caddy_http_requests_total because
     * only the histogram carries the status code.
     *
     * @param Result $result
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @return void
     */
    private function addHttpRows(Result $result, array $samples): void
    {
        $section = 'HTTP';

        $inFlight = $this->total($samples, 'caddy_http_requests_in_flight');
        if ($inFlight !== null) {
            $result->add($section, 'In Flight', $this->formatter->number($inFlight));
        }

        $codes = $this->split($samples, 'caddy_http_request_duration_seconds_count', 'code');
        $requests = 0.0;
        foreach ($codes as ['value' => $count]) {
            $requests += $count;
        }
        if ($requests <= 0) {
            return;
        }

        $result->add(
            $section,
            'Requests',
            $this->formatter->number($requests),
            Status::INFO,
            'Since Caddy last started. Counters reset with the container.'
        );

        $durationSum = $this->total($samples, 'caddy_http_request_duration_seconds_sum');
        if ($durationSum !== null) {
            $result->add(
                $section,
                'Average Request Time',
                $this->formatter->seconds($durationSum / $requests),
                Status::INFO,
                'End to end, including waiting for a PHP thread. Averaged over every request since start, so '
                . 'a bad hour is invisible here.'
            );
        }

        foreach ($codes as ['label' => $code, 'value' => $count]) {
            if ($code === '') {
                continue;
            }

            $share = $this->formatter->ratio($count, $requests);
            $isServerError = (int) $code >= 500 && (int) $code < 600;

            $result->add(
                'Status Codes',
                $code,
                sprintf('%s (%s)', $this->formatter->number($count), $this->formatter->percent($share, 2)),
                $isServerError
                    ? $this->status->forCeiling($share, self::ERROR_RATE_WARN_PCT, self::ERROR_RATE_ERROR_PCT)
                    : Status::INFO,
                $isServerError
                    ? 'Rated against total requests, because a handful since the container started is not the '
                    . 'same as a steady trickle.'
                    : 'Counted since Caddy started.'
            );
        }
    }

    /**
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

        $resident = $this->prometheus->sum($samples, 'process_resident_memory_bytes');
        if ($resident !== null) {
            $result->add(
                $section,
                'Resident Memory',
                $this->formatter->bytes($resident),
                Status::INFO,
                'Caddy and every PHP thread together, since PHP runs inside the same process. Compare it '
                . 'against the container memory limit.'
            );
        }
    }

    /**
     * Total one family by exact name, leaving out the scrape's own requests.
     *
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @param string $name
     * @return float|null Null when the family is absent entirely.
     */
    private function total(array $samples, string $name): ?float
    {
        $total = null;
        foreach ($samples as $sample) {
            if ($sample['name'] !== $name || ($sample['labels']['handler'] ?? '') === self::SCRAPE_HANDLER) {
                continue;
            }
            $total = ($total ?? 0.0) + $sample['value'];
        }

        return $total;
    }

    /**
     * Split one family by the value of a NAMED label.
     *
     * PrometheusMetrics::breakdown() groups on every label at once, which is
     * right for imgproxy's single-label families but not here: Caddy's
     * histogram carries server, handler, method and code together, and only
     * the code is wanted. Same list shape as breakdown(), for the same reason.
     *
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @param string $name
     * @param string $label
     * @return array<int, array{label: string, value: float}>
     */
    private function split(array $samples, string $name, string $label): array
    {
        $totals = [];
        foreach ($samples as $sample) {
            if ($sample['name'] !== $name || ($sample['labels']['handler'] ?? '') === self::SCRAPE_HANDLER) {
                continue;
            }
            $key = $sample['labels'][$label] ?? '';
            $totals[$key] = ($totals[$key] ?? 0.0) + $sample['value'];
        }

        $split = [];
        foreach ($totals as $key => $value) {
            $split[] = ['label' => (string) $key, 'value' => $value];
        }
        usort($split, static fn (array $a, array $b): int => strnatcmp($a['label'], $b['label']));

        return $split;
    }
}
