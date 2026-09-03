<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Standalone;

use Magenx\Platform\Model\Metric\Status;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Magenx\Platform\Model\Metric\Status
 */
class StatusTest extends TestCase
{
    private Status $status;

    protected function setUp(): void
    {
        $this->status = new Status();
    }

    public function testWorstRanksBySeverity(): void
    {
        $this->assertSame(Status::WARN, $this->status->worst(Status::OK, Status::WARN));
        $this->assertSame(Status::WARN, $this->status->worst(Status::WARN, Status::OK));
        $this->assertSame(Status::UNAVAILABLE, $this->status->worst(Status::WARN, Status::UNAVAILABLE));
        $this->assertSame(Status::ERROR, $this->status->worst(Status::UNAVAILABLE, Status::ERROR));
        $this->assertSame(Status::ERROR, $this->status->worst(Status::ERROR, Status::UNAVAILABLE));
    }

    public function testInfoAndOkRankEqualSoTheLeftSideWins(): void
    {
        // INFO exists so a plain fact does not have to claim it is "ok", which
        // means neither may outrank the other when rows roll up.
        $this->assertSame(Status::INFO, $this->status->worst(Status::INFO, Status::OK));
        $this->assertSame(Status::OK, $this->status->worst(Status::OK, Status::INFO));
    }

    public function testAnUnknownStatusIsTreatedAsHarmless(): void
    {
        $this->assertSame(Status::WARN, $this->status->worst('nonsense', Status::WARN));
        $this->assertSame(Status::WARN, $this->status->worst(Status::WARN, 'nonsense'));
    }

    /**
     * @dataProvider ceilingProvider
     */
    public function testForCeiling(float $value, string $expected): void
    {
        $this->assertSame($expected, $this->status->forCeiling($value, 85.0, 95.0));
    }

    public static function ceilingProvider(): array
    {
        return [
            'well under' => [50.0, Status::OK],
            'just under the warning' => [84.9, Status::OK],
            'exactly at the warning' => [85.0, Status::WARN],
            'between' => [90.0, Status::WARN],
            'exactly at the error' => [95.0, Status::ERROR],
            'over' => [99.0, Status::ERROR],
        ];
    }

    /**
     * @dataProvider floorProvider
     */
    public function testForFloorInvertsTheComparison(float $value, string $expected): void
    {
        // The InnoDB buffer pool hit rate: 99% warns, 95% is an error.
        $this->assertSame($expected, $this->status->forFloor($value, 99.0, 95.0));
    }

    public static function floorProvider(): array
    {
        return [
            'healthy' => [99.9, Status::OK],
            'just above the warning' => [99.1, Status::OK],
            'exactly at the warning' => [99.0, Status::WARN],
            'between' => [97.0, Status::WARN],
            'exactly at the error' => [95.0, Status::ERROR],
            'under' => [10.0, Status::ERROR],
        ];
    }
}
