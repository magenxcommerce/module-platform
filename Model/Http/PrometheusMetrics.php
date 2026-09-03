<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Http;

/**
 * Just enough of the Prometheus text exposition format to read a status page.
 *
 * Prometheus exposition is line-based plain text, which is why scraping it needs
 * no client library — the same reason the Nginx collector parses stub_status
 * with a regex. This is a reader, not an implementation: it flattens samples to
 * name => value and throws the label sets away, because nothing on this
 * dashboard slices a metric by label.
 */
class PrometheusMetrics
{
    /**
     * Flatten an exposition body to metric name => value.
     *
     * Labels are dropped from the key, so a metric exposed once per label set
     * collapses to whichever sample came last. That is fine for the counters and
     * gauges this dashboard reads and wrong for anything dimensional — do not
     * grow this into a general client.
     *
     * @param string $body
     * @return array<string, float>
     */
    public function parse(string $body): array
    {
        $metrics = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            // "#" is both HELP/TYPE metadata and ordinary comments.
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // "name" or "name{label="v",...}" then whitespace then the value.
            // A trailing timestamp may follow the value and is ignored.
            if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{.*\})?\s+(\S+)/', $line, $matches) !== 1) {
                continue;
            }

            $value = $matches[3];
            // NaN and the infinities are legal values and mean "no useful
            // number" — most often the +Inf bucket of a histogram. Storing them
            // would put "inf" on the page.
            if (!is_numeric($value)) {
                continue;
            }

            $metrics[$matches[1]] = (float) $value;
        }

        return $metrics;
    }

    /**
     * Read one metric, tolerating an exporter-configured name prefix.
     *
     * imgproxy namespaces every metric when IMGPROXY_PROMETHEUS_NAMESPACE is
     * set and leaves them bare when it is not, so "requests_total" may arrive as
     * "imgproxy_requests_total". Matching on the suffix means the admin never
     * has to tell us which they chose.
     *
     * @param array<string, float> $metrics
     * @param string $name
     * @return float|null
     */
    public function get(array $metrics, string $name): ?float
    {
        if (array_key_exists($name, $metrics)) {
            return $metrics[$name];
        }

        $suffix = '_' . $name;
        foreach ($metrics as $key => $value) {
            if (str_ends_with($key, $suffix)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The mean of a histogram or summary, from its _sum and _count companions.
     *
     * @param array<string, float> $metrics
     * @param string $name Metric name without the _sum / _count suffix.
     * @return float|null
     */
    public function average(array $metrics, string $name): ?float
    {
        $sum = $this->get($metrics, $name . '_sum');
        $count = $this->get($metrics, $name . '_count');

        if ($sum === null || $count === null || $count <= 0) {
            return null;
        }

        return $sum / $count;
    }
}
