<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model;

/**
 * Unit formatting shared by every collector, so "512 MB" means the same thing
 * on the Redis tab as it does on the OpenSearch one.
 */
class Formatter
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * @param float|int|string|null $bytes
     * @return string
     */
    public function bytes($bytes): string
    {
        if ($bytes === null || $bytes === '' || !is_numeric($bytes)) {
            return 'n/a';
        }

        $value = (float) $bytes;
        if ($value < 0) {
            return 'unlimited';
        }

        $unit = 0;
        while ($value >= 1024 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $value, self::UNITS[$unit]);
    }

    /**
     * @param float|int|string|null $seconds
     * @return string
     */
    public function duration($seconds): string
    {
        if ($seconds === null || !is_numeric($seconds)) {
            return 'n/a';
        }

        $seconds = (int) $seconds;
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('%dd %dh', $days, $hours);
        }
        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }

    /**
     * @param float $ratio Already a percentage, not a fraction.
     * @param int $decimals
     * @return string
     */
    public function percent(float $ratio, int $decimals = 1): string
    {
        return sprintf('%.' . $decimals . 'f%%', $ratio);
    }

    /**
     * Percentage of a whole, guarding the zero denominator every collector hits
     * on a freshly started container.
     *
     * @param float $part
     * @param float $whole
     * @return float
     */
    public function ratio(float $part, float $whole): float
    {
        return $whole > 0 ? ($part / $whole) * 100 : 0.0;
    }

    /**
     * @param float|int|string|null $number
     * @return string
     */
    public function number($number): string
    {
        if ($number === null || !is_numeric($number)) {
            return 'n/a';
        }

        return number_format((float) $number, 0, '.', ' ');
    }

    /**
     * A latency, in whatever unit reads best at that magnitude.
     *
     * @param float $seconds
     * @return string
     */
    public function seconds(float $seconds): string
    {
        if ($seconds >= 1.0) {
            return sprintf('%.2f s', $seconds);
        }
        if ($seconds >= 0.001) {
            return sprintf('%.1f ms', $seconds * 1000);
        }

        return sprintf('%.0f µs', $seconds * 1000000);
    }

    /**
     * "used / total (pct)" — the shape most saturation metrics want.
     *
     * @param float $used
     * @param float $total
     * @return string
     */
    public function bytesOf(float $used, float $total): string
    {
        if ($total <= 0) {
            return $this->bytes($used);
        }

        return sprintf(
            '%s / %s (%s)',
            $this->bytes($used),
            $this->bytes($total),
            $this->percent($this->ratio($used, $total))
        );
    }
}
