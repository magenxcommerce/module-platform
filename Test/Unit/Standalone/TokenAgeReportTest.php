<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Standalone;

use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Integration\TokenAgeReport;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Integration tokens never expire on their own, so their age is the judgement.
 */
#[CoversClass(TokenAgeReport::class)]
class TokenAgeReportTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /**
     * @return array<string, array{int, string}>
     */
    public static function ages(): array
    {
        return [
            'fresh' => [10, Status::OK],
            'a day before rotation' => [89, Status::OK],
            'due' => [90, Status::WARN],
            'a day before overdue' => [179, Status::WARN],
            'overdue' => [180, Status::ERROR],
        ];
    }

    #[DataProvider('ages')]
    public function testAgeDecidesTheStatus(int $days, string $expected): void
    {
        $row = $this->build([$this->row('Claude', $days)])['sections'][0]['rows'][0];

        $this->assertSame($expected, $row['status']);
        $this->assertSame($days . ' days', $row['value']);
    }

    public function testOverdueTokensSayHowToRotate(): void
    {
        $row = $this->build([$this->row('ERP', 200, full: true, mcp: true)])['sections'][0]['rows'][0];

        $this->assertStringContainsString('Reauthorize', $row['hint']);
        $this->assertStringContainsString('Full admin access', $row['hint']);
        $this->assertStringContainsString('AI MCP server', $row['hint']);
    }

    public function testAFreshTokenIsNotToldToRotate(): void
    {
        $row = $this->build([$this->row('Claude', 5)])['sections'][0]['rows'][0];

        $this->assertStringNotContainsString('Reauthorize', $row['hint']);
    }

    public function testAnIntegrationWithoutATokenIsOnlyReported(): void
    {
        $tab = $this->build([['integration_id' => 4, 'name' => 'Old', 'status' => 0, 'token_created_at' => null]]);

        $this->assertSame(Status::INFO, $tab['status']);
        $this->assertSame('no live token', $tab['sections'][0]['rows'][0]['value']);
        $this->assertStringContainsString('none holding a live token', $tab['summary']);
    }

    public function testTheSummaryCountsLiveAndStaleTokens(): void
    {
        $tab = $this->build([$this->row('A', 10), $this->row('B', 120), $this->row('C', 400)]);

        $this->assertSame(Status::ERROR, $tab['status']);
        $this->assertSame('3 live tokens, oldest 400 days; 2 past the 90-day rotation.', $tab['summary']);
    }

    public function testAnInactiveIntegrationStillHoldingATokenIsFlagged(): void
    {
        $row = $this->row('Retired', 30);
        $row['status'] = 0;

        $hint = $this->build([$row])['sections'][0]['rows'][0]['hint'];

        $this->assertStringContainsString('not active', $hint);
    }

    public function testNoIntegrationsIsHealthy(): void
    {
        $row = $this->build([])['sections'][0]['rows'][0];

        $this->assertSame(Status::OK, $row['status']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function build(array $rows): array
    {
        $result = new Result(new Status());
        (new TokenAgeReport(new Formatter()))->build($result, $rows, self::NOW);

        return $result->toArray();
    }

    /**
     * @param string $name
     * @param int $days
     * @param bool $full
     * @param bool $mcp
     * @return array<string, mixed>
     */
    private function row(string $name, int $days, bool $full = false, bool $mcp = false): array
    {
        return [
            'integration_id' => 1,
            'name' => $name,
            'status' => 1,
            'token_created_at' => gmdate('Y-m-d H:i:s', self::NOW - $days * 86400),
            'full_access' => $full ? '1' : '0',
            'mcp_access' => $mcp ? '1' : '0',
        ];
    }
}
