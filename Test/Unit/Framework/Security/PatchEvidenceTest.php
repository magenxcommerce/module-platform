<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework\Security;

use Magenx\Platform\Model\Security\PatchEvidence;
use Magenx\Platform\Model\Security\PatchVerifier;
use Magenx\Platform\Model\Security\ProjectFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Applied (composer-patches recorded it), declared (composer.json asks for it),
 * present (a file in a patch directory) or nothing found — and nothing found is
 * never "missing". Where the patch file is on disk, the code outranks them all.
 */
#[CoversClass(PatchEvidence::class)]
#[CoversClass(PatchVerifier::class)]
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
            [
                'composer_patches_v1',
                'composer_patches_v2',
                'composer_json',
                'patch_status_registry',
                'patch_dir',
                'm2_hotfixes',
                'patch_status_cache',
            ],
            array_column($evidence->collect()['sources'], 'source')
        );
    }

    public function testAPatchAppliedByHandIsVerifiedInTheCode(): void
    {
        $evidence = $this->evidence([], ['m2-hotfixes/APSB26-146_VULN-39341_2.4.8-p5.patch'], [
            'm2-hotfixes/APSB26-146_VULN-39341_2.4.8-p5.patch' => self::PATCH,
            'vendor/magento/framework/Escaper.php' => "<?php\nreturn htmlspecialchars(\$v);\n",
        ]);

        $match = $evidence->match(['VULN-39341', 'APSB26-146']);

        $this->assertSame(PatchEvidence::VERIFIED, $match['status']);
        $this->assertSame('m2_hotfixes', $match['matches'][0]['source']);
        $this->assertSame(PatchEvidence::PRESENT, $match['matches'][0]['record_status']);
    }

    public function testADiffPatchStatusDownloadedIsNoEvidenceUntilTheCodeHasIt(): void
    {
        $registry = ['var/patch_metadata/.patch_registry_cache.json' => ['patches' => [
            'ACSD-2026-09-001' => ['file_name' => '248p5-2026-09-001-CE.diff', 'cves' => ['CVE-2026-76200']],
        ]]];
        $cached = ['var/patch_metadata/.patch_diffs/248p5-2026-09-001-CE.diff'];
        $unpatched = [$cached[0] => self::PATCH, 'vendor/magento/framework/Escaper.php' => "<?php\nreturn \$v;\n"];

        $missing = $this->evidence($registry, $cached, ['var/patch_metadata/.patch_diffs/other.diff' => ''])
            ->match(['2026-sep', 'APSB26-138'], ['CVE-2026-76200']);
        $this->assertSame(PatchEvidence::NO_EVIDENCE, $missing['status']);
        $this->assertSame('patch_status_cache', $missing['matches'][0]['source']);

        $this->assertSame(
            PatchEvidence::NOT_IN_CODE,
            $this->evidence($registry, $cached, $unpatched)->match(['nothing-by-name'], ['cve-2026-76200'])['status']
        );

        $patched = [$cached[0] => self::PATCH, 'vendor/magento/framework/Escaper.php' => "<?php\nreturn htmlspecialchars(\$v);\n"];
        $match = $this->evidence($registry, $cached, $patched)->match(['2026-sep'], ['CVE-2026-76200']);
        $this->assertSame(PatchEvidence::VERIFIED, $match['status']);
        $this->assertSame('ACSD-2026-09-001', $match['matches'][0]['patch_id']);
    }

    public function testAPatchKeptInPatchesIsCheckedLikeAHotfix(): void
    {
        $evidence = $this->evidence([], ['patches/VULN-39341_2.4.8-p5.patch', 'patches/README.md'], [
            'patches/VULN-39341_2.4.8-p5.patch' => self::PATCH,
            'vendor/magento/framework/Escaper.php' => "<?php\nreturn htmlspecialchars(\$v);\n",
        ]);

        $match = $evidence->match(['VULN-39341']);

        $this->assertSame(PatchEvidence::VERIFIED, $match['status']);
        $this->assertSame('patch_dir', $match['matches'][0]['source']);
        $this->assertCount(1, $match['matches']);
    }

    public function testAComposerReferenceLosesOnlyItsLeadingDotSlash(): void
    {
        $evidence = $this->evidence(
            ['composer.json' => ['extra' => ['patches' => ['magento/framework' => [
                'VULN-39341' => './patches/vuln.patch',
                'VULN-11111' => '.patches/hidden.patch',
            ]]]]],
            [],
            [
                'patches/vuln.patch' => self::PATCH,
                '.patches/hidden.patch' => self::PATCH,
                'vendor/magento/framework/Escaper.php' => "<?php\nreturn htmlspecialchars(\$v);\n",
            ]
        );

        $this->assertSame(PatchEvidence::VERIFIED, $evidence->match(['VULN-39341'])['status']);
        $this->assertSame(PatchEvidence::VERIFIED, $evidence->match(['VULN-11111'])['status']);
    }

    public function testTheCodeOverridesARecordThatSaysApplied(): void
    {
        $evidence = $this->evidence(
            ['patches.lock.json' => ['patches' => [
                'magento/framework' => [['description' => 'VULN-39341', 'url' => 'patches/vuln.patch']],
            ]]],
            [],
            [
                'patches/vuln.patch' => self::PATCH,
                'vendor/magento/framework/Escaper.php' => "<?php\nreturn \$v;\n",
            ]
        );

        $match = $evidence->match(['VULN-39341']);

        $this->assertSame(PatchEvidence::NOT_IN_CODE, $match['status']);
        $this->assertSame(PatchVerifier::NOT_APPLIED, $match['matches'][0]['code']['state']);
    }

    private const PATCH = <<<'DIFF'
        --- a/vendor/magento/framework/Escaper.php
        +++ b/vendor/magento/framework/Escaper.php
        @@ -1,2 +1,2 @@
         <?php
        -return $v;
        +return htmlspecialchars($v);
        DIFF;

    /**
     * @param array<string, array<mixed>> $json Path => decoded contents; anything else is absent.
     * @param string[] $patchFiles Files in patches/, m2-hotfixes/ or patch-status's cache.
     * @param array<string, string> $text Path => contents of patch and code files.
     */
    private function evidence(array $json, array $patchFiles = [], array $text = []): PatchEvidence
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
        $files->method('listDirectory')->willReturnCallback(
            static function (string $directory) use ($patchFiles): array {
                $found = array_values(array_filter(
                    $patchFiles,
                    static fn (string $path): bool => str_starts_with($path, $directory . '/')
                ));

                return ['files' => $found, 'state' => $found === [] ? 'absent' : 'read', 'path' => $directory];
            }
        );
        $files->method('readText')->willReturnCallback(
            static fn (string $path): array => [
                'data' => $text[$path] ?? null,
                'state' => isset($text[$path]) ? 'read' : 'absent',
                'path' => $path,
            ]
        );
        $files->method('vendorPath')->willReturn('vendor');

        return new PatchEvidence($files, new PatchVerifier($files));
    }
}
