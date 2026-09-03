<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Standalone;

use Magenx\Platform\Model\Http\PrometheusMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrometheusMetrics::class)]
class PrometheusMetricsTest extends TestCase
{
    private PrometheusMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new PrometheusMetrics();
    }

    public function testParseSkipsCommentsAndBlankLines(): void
    {
        $samples = $this->metrics->parse(
            "# HELP requests_total How many\n"
            . "# TYPE requests_total counter\n"
            . "\n"
            . "requests_total 10\n"
        );

        $this->assertCount(1, $samples);
        $this->assertSame('requests_total', $samples[0]['name']);
        $this->assertSame(10.0, $samples[0]['value']);
        $this->assertSame([], $samples[0]['labels']);
    }

    public function testParseIgnoresATrailingTimestamp(): void
    {
        $samples = $this->metrics->parse("requests_total 10 1700000000000\n");

        $this->assertSame(10.0, $samples[0]['value']);
    }

    public function testParseDropsNonNumericValues(): void
    {
        // NaN and the infinities are legal exposition and mean "no useful
        // number" — most often a histogram's +Inf bucket. Keeping them would
        // print "inf" on the page.
        $samples = $this->metrics->parse(
            "a_bucket{le=\"+Inf\"} NaN\n"
            . "b_total +Inf\n"
            . "c_total 3\n"
        );

        $this->assertCount(1, $samples);
        $this->assertSame('c_total', $samples[0]['name']);
    }

    public function testParseReadsLabels(): void
    {
        $samples = $this->metrics->parse('errors_total{type="timeout",source="origin"} 4');

        $this->assertSame(['type' => 'timeout', 'source' => 'origin'], $samples[0]['labels']);
    }

    public function testParseUnescapesLabelValues(): void
    {
        $samples = $this->metrics->parse('errors_total{message="a \"quoted\" thing"} 1');

        $this->assertSame('a "quoted" thing', $samples[0]['labels']['message']);
    }

    public function testSumTotalsEveryLabelSetInTheFamily(): void
    {
        // Not a first-match lookup: imgproxy splits errors_total by type, so
        // reading one sample would report one type's count as the total.
        $samples = $this->metrics->parse(
            "errors_total{type=\"downloading\"} 2\n"
            . "errors_total{type=\"processing\"} 3\n"
            . "errors_total{type=\"timeout\"} 1.5\n"
        );

        $this->assertSame(6.5, $this->metrics->sum($samples, 'errors_total'));
    }

    public function testSumIsNullWhenTheFamilyIsAbsentEntirely(): void
    {
        // Null, not 0.0 — the collector renders no row at all rather than
        // claiming a backend reported zero.
        $this->assertNull($this->metrics->sum($this->metrics->parse('requests_total 1'), 'errors_total'));
    }

    public function testSumToleratesAnExporterConfiguredNamespace(): void
    {
        // imgproxy prefixes every metric when IMGPROXY_PROMETHEUS_NAMESPACE is
        // set and leaves them bare when it is not, so the admin never has to
        // tell the module which they chose.
        $bare = $this->metrics->parse('requests_total 10');
        $prefixed = $this->metrics->parse('imgproxy_requests_total 10');

        $this->assertSame(10.0, $this->metrics->sum($bare, 'requests_total'));
        $this->assertSame(10.0, $this->metrics->sum($prefixed, 'requests_total'));
    }

    public function testNamespaceToleranceMatchesOnAnUnderscoreBoundary(): void
    {
        $samples = $this->metrics->parse(
            "imgproxy_workers 4\n"
            . "imgproxy_workers_utilization 55\n"
        );

        // "workers" must not swallow "workers_utilization".
        $this->assertSame(4.0, $this->metrics->sum($samples, 'workers'));
        $this->assertSame(55.0, $this->metrics->sum($samples, 'workers_utilization'));
    }

    public function testSuffixMatchingIsNotNamespaceAware(): void
    {
        // Pinned deliberately, because it is the sharp edge of matching on a
        // suffix: any family whose name ends in "_<wanted>" joins the total,
        // whether or not the leading part is really an exporter namespace. The
        // wanted names the collectors ask for have to be specific enough that
        // this cannot happen against the metric set they read — this test is
        // here so that the day one of them is not, it is a failing test rather
        // than a wrong number on the page.
        $samples = $this->metrics->parse(
            "workers 4\n"
            . "vips_max_workers 8\n"
        );

        $this->assertSame(12.0, $this->metrics->sum($samples, 'workers'));
    }

    public function testBreakdownGroupsOnTheLabelValueAndSortsNaturally(): void
    {
        $samples = $this->metrics->parse(
            "status_codes_total{status=\"500\"} 1\n"
            . "status_codes_total{status=\"200\"} 5\n"
            . "status_codes_total{status=\"404\"} 2\n"
        );

        $this->assertSame(
            [
                ['label' => '200', 'value' => 5.0],
                ['label' => '404', 'value' => 2.0],
                ['label' => '500', 'value' => 1.0],
            ],
            $this->metrics->breakdown($samples, 'status_codes_total')
        );
    }

    public function testBreakdownKeepsNumericLabelsAsStrings(): void
    {
        // The reason breakdown() returns a list rather than a label-keyed map:
        // PHP casts a numeric-string array key to an integer, so a map hands
        // back int 200 for status "200" while leaving "timeout" a string — and
        // the caller then passes an int where a string is declared and fatals
        // on some deployments but not others, depending on which labels the
        // backend happens to emit.
        $breakdown = $this->metrics->breakdown(
            $this->metrics->parse("status_codes_total{status=\"200\"} 5\n"),
            'status_codes_total'
        );

        $this->assertIsString($breakdown[0]['label']);
        $this->assertSame('200', $breakdown[0]['label']);
    }

    public function testBreakdownSumsRepeatedLabelSets(): void
    {
        $samples = $this->metrics->parse(
            "errors_total{type=\"timeout\"} 2\n"
            . "imgproxy_errors_total{type=\"timeout\"} 3\n"
        );

        $this->assertSame(
            [['label' => 'timeout', 'value' => 5.0]],
            $this->metrics->breakdown($samples, 'errors_total')
        );
    }

    public function testAverageDividesSumByCount(): void
    {
        $samples = $this->metrics->parse(
            "request_duration_seconds_sum 10\n"
            . "request_duration_seconds_count 4\n"
        );

        $this->assertSame(2.5, $this->metrics->average($samples, 'request_duration_seconds'));
    }

    public function testAverageNeverPicksUpABucketLine(): void
    {
        // The "_sum" family must not absorb the histogram's buckets, or the
        // mean comes out as nonsense.
        $samples = $this->metrics->parse(
            "request_duration_seconds_bucket{le=\"0.1\"} 500\n"
            . "request_duration_seconds_bucket{le=\"+Inf\"} 4\n"
            . "request_duration_seconds_sum 10\n"
            . "request_duration_seconds_count 4\n"
        );

        $this->assertSame(2.5, $this->metrics->average($samples, 'request_duration_seconds'));
    }

    public function testAverageCanBeRestrictedToOneLabelValue(): void
    {
        // imgproxy publishes one histogram split by span rather than a metric
        // per phase, so the phases are the same family filtered by label.
        $samples = $this->metrics->parse(
            "request_span_duration_seconds_sum{span=\"queue\"} 1\n"
            . "request_span_duration_seconds_count{span=\"queue\"} 4\n"
            . "request_span_duration_seconds_sum{span=\"processing\"} 30\n"
            . "request_span_duration_seconds_count{span=\"processing\"} 3\n"
        );

        $this->assertSame(0.25, $this->metrics->average($samples, 'request_span_duration_seconds', 'queue'));
        $this->assertSame(10.0, $this->metrics->average($samples, 'request_span_duration_seconds', 'processing'));
    }

    public function testAverageIsNullWhenThereIsNothingToDivide(): void
    {
        $this->assertNull($this->metrics->average($this->metrics->parse('other_total 1'), 'request_duration_seconds'));

        // A count of zero is not a mean of zero.
        $zero = $this->metrics->parse(
            "request_duration_seconds_sum 0\n"
            . "request_duration_seconds_count 0\n"
        );
        $this->assertNull($this->metrics->average($zero, 'request_duration_seconds'));
    }

    public function testParseHandlesCarriageReturnLineEndings(): void
    {
        $samples = $this->metrics->parse("requests_total 10\r\nerrors_total 2\r\n");

        $this->assertCount(2, $samples);
    }
}
