<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Standalone;

use Magenx\Platform\Model\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Magenx\Platform\Model\Formatter
 */
class FormatterTest extends TestCase
{
    private Formatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new Formatter();
    }

    /**
     * @dataProvider bytesProvider
     */
    public function testBytes(mixed $input, string $expected): void
    {
        $this->assertSame($expected, $this->formatter->bytes($input));
    }

    public static function bytesProvider(): array
    {
        return [
            'null is not zero' => [null, 'n/a'],
            'empty string' => ['', 'n/a'],
            'non-numeric' => ['unknown', 'n/a'],
            // -1 is how MySQL, Redis and the JVM all spell "no limit".
            'negative means unlimited' => [-1, 'unlimited'],
            'zero' => [0, '0 B'],
            'no decimals below a kilobyte' => [1023, '1023 B'],
            'exactly one kilobyte' => [1024, '1.0 KB'],
            'one and a half kilobytes' => [1536, '1.5 KB'],
            'numeric string' => ['1048576', '1.0 MB'],
            'petabytes' => [1024 ** 5, '1.0 PB'],
            // The unit list stops at PB rather than overflowing it.
            'beyond the largest unit' => [1024 ** 6, '1024.0 PB'],
        ];
    }

    /**
     * @dataProvider durationProvider
     */
    public function testDuration(mixed $input, string $expected): void
    {
        $this->assertSame($expected, $this->formatter->duration($input));
    }

    public static function durationProvider(): array
    {
        return [
            'null' => [null, 'n/a'],
            'non-numeric' => ['ages', 'n/a'],
            'zero' => [0, '0m'],
            'under a minute' => [59, '0m'],
            'one minute' => [60, '1m'],
            'an exact hour keeps the minutes' => [3600, '1h 0m'],
            'an hour and a minute' => [3661, '1h 1m'],
            'a day drops to hours' => [86400, '1d 0h'],
            'a day and an hour' => [90061, '1d 1h'],
        ];
    }

    public function testPercent(): void
    {
        $this->assertSame('99.5%', $this->formatter->percent(99.5));
        $this->assertSame('50.00%', $this->formatter->percent(50.0, 2));
        $this->assertSame('0.0%', $this->formatter->percent(0.0));
    }

    public function testRatioGuardsTheZeroDenominator(): void
    {
        // Every collector hits this on a freshly started container.
        $this->assertSame(0.0, $this->formatter->ratio(5.0, 0.0));
        $this->assertSame(25.0, $this->formatter->ratio(1.0, 4.0));
        $this->assertSame(100.0, $this->formatter->ratio(4.0, 4.0));
    }

    public function testNumber(): void
    {
        $this->assertSame('n/a', $this->formatter->number(null));
        $this->assertSame('n/a', $this->formatter->number('lots'));
        $this->assertSame('0', $this->formatter->number(0));
        $this->assertSame('1 234 567', $this->formatter->number(1234567));
    }

    /**
     * @dataProvider secondsProvider
     */
    public function testSecondsPicksTheUnitThatReadsBest(float $input, string $expected): void
    {
        $this->assertSame($expected, $this->formatter->seconds($input));
    }

    public static function secondsProvider(): array
    {
        return [
            'seconds' => [1.5, '1.50 s'],
            'the second boundary' => [1.0, '1.00 s'],
            'milliseconds' => [0.25, '250.0 ms'],
            'the millisecond boundary' => [0.001, '1.0 ms'],
            'microseconds' => [0.0005, '500 µs'],
        ];
    }

    public function testBytesOf(): void
    {
        $this->assertSame('512 B / 1.0 KB (50.0%)', $this->formatter->bytesOf(512.0, 1024.0));
        // No total to divide by, so no meaningless percentage.
        $this->assertSame('512 B', $this->formatter->bytesOf(512.0, 0.0));
    }
}
