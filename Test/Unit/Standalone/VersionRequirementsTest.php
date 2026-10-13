<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Standalone;

use Magenx\Platform\Model\Security\VersionRequirements;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Release requirement lists are tested lines, not ranges: "8.4" covers 8.4.22,
 * "11.4" does not cover 11.40, and "redis-7.1" names another product's line.
 */
#[CoversClass(VersionRequirements::class)]
class VersionRequirementsTest extends TestCase
{
    public static function comparisons(): array
    {
        return [
            'patch level inside a listed minor' => ['8.4.22', ['8.3', '8.4'], VersionRequirements::SUPPORTED],
            'any minor of a listed major' => ['3.1.0', ['3'], VersionRequirements::SUPPORTED],
            'vendor suffix on the version' => ['11.4.10-MariaDB-ubu2404', ['11.4', '11.8'], VersionRequirements::SUPPORTED],
            'product name in front' => ['Opensearch 2.19.5', ['3'], VersionRequirements::OLDER],
            'below every listed line' => ['8.4.22', ['8.5'], VersionRequirements::OLDER],
            'above every listed line' => ['8.6.1', ['8.1'], VersionRequirements::NEWER],
            'between listed lines' => ['11.6.2', ['11.4', '11.8'], VersionRequirements::NOT_LISTED],
            'two-digit minor is not its prefix' => ['11.40.1', ['11.4'], VersionRequirements::NEWER],
            'no version entries' => ['7.1.0', ['supported', 'redis-7.1'], VersionRequirements::NOT_COMPARABLE],
            'unreadable installed version' => ['n/a', ['8.4'], VersionRequirements::NOT_COMPARABLE],
        ];
    }

    #[DataProvider('comparisons')]
    public function testCompare(string $installed, array $required, string $expected): void
    {
        $this->assertSame($expected, (new VersionRequirements())->compare($installed, $required));
    }

    public function testAnotherProductsLineIsNotReadAsAVersion(): void
    {
        $requirements = new VersionRequirements();

        $this->assertNull($requirements->normalize('redis-7.1'));
        $this->assertSame('2.19.5', $requirements->normalize('Opensearch 2.19.5'));
    }
}
