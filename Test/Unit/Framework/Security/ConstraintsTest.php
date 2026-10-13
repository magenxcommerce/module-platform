<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework\Security;

use Magenx\Platform\Model\Security\Constraints;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Composer's rules, with "cannot tell" kept apart from "no".
 */
#[CoversClass(Constraints::class)]
class ConstraintsTest extends TestCase
{
    public function testAnAdvisoryRangeIsReadTheWayComposerReadsIt(): void
    {
        $constraints = new Constraints();

        $this->assertTrue($constraints->satisfies('7.9.2', '>=8.0.0,<8.0.1|<7.15.2'));
        $this->assertFalse($constraints->satisfies('7.15.2', '>=8.0.0,<8.0.1|<7.15.2'));
        $this->assertTrue($constraints->satisfies('8.4.22', '~8.3.0||~8.4.0||~8.5.0'));
        $this->assertNull($constraints->satisfies('dev-main', 'this is not a constraint'));
    }

    public function testTwoRangesIntersectOnlyWhereComposerCouldSolveThem(): void
    {
        $constraints = new Constraints();

        $this->assertTrue($constraints->intersect('^7.4', '^7.5'));
        $this->assertFalse($constraints->intersect('^6.0', '^7.5'));
    }

    public function testExactVersionsAreToldApartFromRanges(): void
    {
        $constraints = new Constraints();

        $this->assertTrue($constraints->isExact('103.0.9'));
        $this->assertTrue($constraints->isExact('2.4.8-p5'));
        $this->assertFalse($constraints->isExact('^103.0'));
        $this->assertFalse($constraints->isExact('~8.3.0||~8.4.0'));
    }

    public function testTheNewestStableIgnoresPreReleasesAndBranches(): void
    {
        $constraints = new Constraints();

        $this->assertSame(
            'v22.0.0',
            $constraints->newestStable(['v22.1.0-beta.1', 'dev-master', 'v22.0.0', 'v21.4.0', '22.2.x-dev'])
        );
        $this->assertNull($constraints->newestStable(['1.0.0-alpha1']));
        $this->assertTrue($constraints->greaterThan('2.4.9', '2.4.8-p5'));
        $this->assertTrue($constraints->isMajorApart('v22.0.0', '21.4.0'));
        $this->assertFalse($constraints->isMajorApart('2.4.9', '2.4.8-p5'));
    }
}
