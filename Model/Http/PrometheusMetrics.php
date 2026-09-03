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
 * with a regex.
 *
 * Labels are kept. They looked droppable until the metric list said otherwise:
 * imgproxy splits errors_total by type and status_codes_total by status, so a
 * reader that flattens to name => value reports one label set's count as if it
 * were the whole family. That is a wrong number rather than a missing row, which
 * is the worst kind for a dashboard to produce.
 */
class PrometheusMetrics
{
    /**
     * One line of exposition: a metric name, its label set, and its value.
     *
     * @param string $body
     * @return array<int, array{name: string, labels: array<string, string>, value: float}>
     */
    public function parse(string $body): array
    {
        $samples = [];

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
            // NaN and the infinities are legal values meaning "no useful number"
            // — most often a histogram's +Inf bucket. Storing them would put
            // "inf" on the page.
            if (!is_numeric($value)) {
                continue;
            }

            $samples[] = [
                'name' => $matches[1],
                'labels' => $this->parseLabels($matches[2] ?? ''),
                'value' => (float) $value,
            ];
        }

        return $samples;
    }

    /**
     * Total one metric family across every label set.
     *
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @param string $name
     * @return float|null Null when the family is absent entirely.
     */
    public function sum(array $samples, string $name): ?float
    {
        $total = null;
        foreach ($samples as $sample) {
            if ($this->matchesName($sample['name'], $name)) {
                $total = ($total ?? 0.0) + $sample['value'];
            }
        }

        return $total;
    }

    /**
     * Split one metric family by its label, keyed on the label's VALUE.
     *
     * Keyed on the value rather than the name deliberately: imgproxy documents
     * these families as "separated by status" and "separated by type" without
     * formally naming the labels, and each carries exactly one — so matching on
     * the value is unambiguous here and survives the label being renamed.
     *
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @param string $name
     * @return array<string, float>
     */
    public function breakdown(array $samples, string $name): array
    {
        $byLabel = [];
        foreach ($samples as $sample) {
            if (!$this->matchesName($sample['name'], $name)) {
                continue;
            }
            $key = implode(' ', $sample['labels']);
            $byLabel[$key] = ($byLabel[$key] ?? 0.0) + $sample['value'];
        }

        return $byLabel;
    }

    /**
     * The mean of a histogram or summary, from its _sum and _count companions.
     *
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @param string $name Metric name without the _sum / _count suffix.
     * @param string|null $labelValue Restrict to samples carrying this label value.
     * @return float|null
     */
    public function average(array $samples, string $name, ?string $labelValue = null): ?float
    {
        $sum = $this->sumWhere($samples, $name . '_sum', $labelValue);
        $count = $this->sumWhere($samples, $name . '_count', $labelValue);

        if ($sum === null || $count === null || $count <= 0) {
            return null;
        }

        return $sum / $count;
    }

    /**
     * @param array<int, array{name: string, labels: array<string, string>, value: float}> $samples
     * @param string $name
     * @param string|null $labelValue
     * @return float|null
     */
    private function sumWhere(array $samples, string $name, ?string $labelValue): ?float
    {
        $total = null;
        foreach ($samples as $sample) {
            if (!$this->matchesName($sample['name'], $name)) {
                continue;
            }
            if ($labelValue !== null && !in_array($labelValue, $sample['labels'], true)) {
                continue;
            }
            $total = ($total ?? 0.0) + $sample['value'];
        }

        return $total;
    }

    /**
     * Match a metric name, tolerating an exporter-configured prefix.
     *
     * imgproxy prefixes every metric when IMGPROXY_PROMETHEUS_NAMESPACE is set
     * and leaves them bare when it is not, so "requests_total" may arrive as
     * "imgproxy_requests_total". Matching the suffix means the admin never has
     * to tell us which they chose. The leading underscore keeps this honest:
     * "workers" must not match "vips_max_workers" by accident, and the "_sum"
     * family must never pick up a "_bucket" line.
     *
     * @param string $actual
     * @param string $wanted
     * @return bool
     */
    private function matchesName(string $actual, string $wanted): bool
    {
        return $actual === $wanted || str_ends_with($actual, '_' . $wanted);
    }

    /**
     * Turn `{code="500",type="timeout"}` into a name => value map.
     *
     * @param string $labels
     * @return array<string, string>
     */
    private function parseLabels(string $labels): array
    {
        if ($labels === '') {
            return [];
        }

        $parsed = [];
        // Values are quoted and may contain escaped quotes and backslashes.
        if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"/', $labels, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $parsed[$match[1]] = str_replace(['\\"', '\\\\', '\\n'], ['"', '\\', "\n"], $match[2]);
            }
        }

        return $parsed;
    }
}
