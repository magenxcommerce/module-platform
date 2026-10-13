<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework\Security;

use Magenx\Platform\Model\Security\PatchEvidence;
use Magenx\Platform\Model\Security\ProjectFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Applied (composer-patches recorded it), declared (composer.json asks for it),
 * present (a file in m2-hotfixes/) or nothing found — and nothing found is
 * never "missing".
 */
#[CoversClass(PatchEvidence::class)]
class PatchEvidenceTest extends TestCase
{
    public function testComposerPatchesOneRecordsCountAsApplied(): void
    {
        $evidence = $this->evidence(['vendor/composer/installed.json' => ['packages' => [
            [
                'name' => 'magento/module-catalog',
                'extra' => ['patches_applied' => ['Adobe VULN-39341' => 'patches/VULN-39341_248-p3.patch']],
            ],
            ['name' => 'magento/module-cms'],
        ]]]);

        $match = $evidence->match(['VULN-39341', 'APSB26-146']);

        $this->assertSame(PatchEvidence::APPLIED, $match['status']);
        $this->assertSame('magento/module-catalog', $match['matches'][0]['package']);
    }

    public function testComposerPatchesTwoLockCountsAsAppliedAndUnderscoresMatchHyphens(): void
    {
        $evidence = $this->evidence(['patches.lock.json' => ['patches' => [
            'magento/module-sales' => [['description' => 'APSB26-73 fix', 'url' => 'patches/2026_jul.patch']],
        ]]]);

        $this->assertSame(PatchEvidence::APPLIED, $evidence->match(['2026-jul'])['status']);
        $this->assertSame(PatchEvidence::APPLIED, $evidence->match(['APSB26-73'])['status']);
    }

    public function testADeclarationIsNotARecordOfApplying(): void
    {
        $evidence = $this->evidence([
            'composer.json' => ['extra' => [
                'patches' => ['magento/framework' => ['VULN-39341 hotfix' => 'patches/vuln.patch']],
                'patches-file' => './patches.json',
            ]],
            'patches.json' => ['patches' => ['magento/module-customer' => ['2026-aug patch' => 'patches/aug.patch']]],
        ]);

        $this->assertSame(PatchEvidence::DECLARED, $evidence->match(['VULN-39341'])['status']);
        $this->assertSame(PatchEvidence::DECLARED, $evidence->match(['2026-aug'])['status']);
    }

    public function testAHotfixFileIsPresentAndTheStrongestRecordWins(): void
    {
        $evidence = $this->evidence(
            ['patches.lock.json' => ['patches' => ['magento/framework' => [['description' => '2026-sep', 'url' => 'x']]]]],
            ['m2-hotfixes/VULN-39341_248-p3.patch', 'm2-hotfixes/2026-sep.patch', 'm2-hotfixes/README.md']
        );

        $this->assertSame(PatchEvidence::PRESENT, $evidence->match(['VULN-39341'])['status']);
        $this->assertSame('VULN-39341_248-p3.patch', $evidence->match(['VULN-39341'])['matches'][0]['description']);
        $this->assertSame(PatchEvidence::APPLIED, $evidence->match(['2026-sep'])['status']);
    }

    public function testEverySourceIsAccountedForWhenNothingIsFound(): void
    {
        $evidence = $this->evidence([]);

        $this->assertSame(PatchEvidence::NO_EVIDENCE, $evidence->match(['VULN-39341'])['status']);
        $this->assertSame(
            ['composer_patches_v1', 'composer_patches_v2', 'composer_json', 'm2_hotfixes'],
            array_column($evidence->collect()['sources'], 'source')
        );
    }

    /**
     * @param array<string, array<mixed>> $json Path => decoded contents; anything else is absent.
     * @param string[] $hotfixes
     */
    private function evidence(array $json, array $hotfixes = []): PatchEvidence
    {
        $files = $this->createMock(ProjectFiles::class);
        $files->method('readJson')->willReturnCallback(
            static fn (string $path): array => [
                'data' => $json[$path] ?? null,
                'state' => isset($json[$path]) ? 'read' : 'absent',
                'path' => $path,
            ]
        );
        $files->method('installedJson')->willReturnCallback(
            static fn (): array => $files->readJson('vendor/composer/installed.json')
        );
        $files->method('listDirectory')->willReturn([
            'files' => $hotfixes,
            'state' => $hotfixes === [] ? 'absent' : 'read',
            'path' => 'm2-hotfixes',
        ]);

        return new PatchEvidence($files);
    }
}
