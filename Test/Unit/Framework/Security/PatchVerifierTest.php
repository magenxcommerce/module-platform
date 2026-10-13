<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework\Security;

use Magenx\Platform\Model\Security\PatchVerifier;
use Magenx\Platform\Model\Security\ProjectFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A patch is judged by the code it changes: after-image present is applied,
 * before-image present is not, and Adobe's composer and git layouts both land
 * on vendor/.
 */
#[CoversClass(PatchVerifier::class)]
class PatchVerifierTest extends TestCase
{
    private const UNPATCHED = <<<'PHP'
        <?php
        class Escaper
        {
            public function escape(string $value): string
            {
                return $value;
            }

            public function url(string $url): string
            {
                return $url;
            }
        }
        PHP;

    private const PATCHED = <<<'PHP'
        <?php
        class Escaper
        {
            public function escape(string $value): string
            {
                return htmlspecialchars($value, ENT_QUOTES);
            }

            public function url(string $url): string
            {
                if (str_starts_with($url, 'javascript:')) {
                    return '';
                }
                return $url;
            }
        }
        PHP;

    private const COMPOSER_PATCH = <<<'DIFF'
        diff --git a/vendor/magento/module-catalog-inventory/Model/Escaper.php b/vendor/magento/module-catalog-inventory/Model/Escaper.php
        index 1111111..2222222 100644
        --- a/vendor/magento/module-catalog-inventory/Model/Escaper.php
        +++ b/vendor/magento/module-catalog-inventory/Model/Escaper.php
        @@ -3,7 +3,7 @@ class Escaper
         {
             public function escape(string $value): string
             {
        -        return $value;
        +        return htmlspecialchars($value, ENT_QUOTES);
             }

             public function url(string $url): string
        @@ -9,5 +9,8 @@ class Escaper
             public function url(string $url): string
             {
        +        if (str_starts_with($url, 'javascript:')) {
        +            return '';
        +        }
                 return $url;
             }
         }
        DIFF;

    private const TARGET = 'vendor/magento/module-catalog-inventory/Model/Escaper.php';

    public function testEveryHunkInTheCodeIsVerified(): void
    {
        $result = $this->verifier([self::TARGET => self::PATCHED, 'patches/a.patch' => self::COMPOSER_PATCH])
            ->verify('patches/a.patch');

        $this->assertSame(PatchVerifier::VERIFIED, $result['state']);
        $this->assertSame(2, $result['hunks']);
        $this->assertSame(self::TARGET, $result['files'][0]['resolved']);
    }

    public function testUnpatchedCodeIsNotApplied(): void
    {
        $result = $this->verifier([self::TARGET => self::UNPATCHED, 'patches/a.patch' => self::COMPOSER_PATCH])
            ->verify('patches/a.patch');

        $this->assertSame(PatchVerifier::NOT_APPLIED, $result['state']);
        $this->assertSame(2, $result['not_applied']);
    }

    public function testOneHunkOfTwoIsPartial(): void
    {
        $half = str_replace('return $value;', 'return htmlspecialchars($value, ENT_QUOTES);', self::UNPATCHED);
        $result = $this->verifier([self::TARGET => $half, 'patches/a.patch' => self::COMPOSER_PATCH])
            ->verify('patches/a.patch');

        $this->assertSame(PatchVerifier::PARTIAL, $result['state']);
        $this->assertSame(1, $result['applied']);
        $this->assertSame(1, $result['not_applied']);
    }

    public function testTheGitLayoutResolvesToVendorAndIndentationAndCrlfDoNotMatter(): void
    {
        $git = str_replace(
            'vendor/magento/module-catalog-inventory/',
            'app/code/Magento/CatalogInventory/',
            self::COMPOSER_PATCH
        );
        $reindented = str_replace("\n", "\r\n", str_replace('    ', "\t", self::PATCHED));

        $result = $this->verifier([self::TARGET => $reindented, 'patches/2026-sep/git.patch' => $git])
            ->verify('patches/2026-sep/git.patch');

        $this->assertSame(PatchVerifier::VERIFIED, $result['state']);
        $this->assertSame(self::TARGET, $result['files'][0]['resolved']);
    }

    public function testFrameworkPathsResolveToTheirPackage(): void
    {
        $patch = str_replace(
            'vendor/magento/module-catalog-inventory/Model/',
            'lib/internal/Magento/Framework/Escaper/',
            self::COMPOSER_PATCH
        );

        $result = $this->verifier([
            'vendor/magento/framework/Escaper/Escaper.php' => self::PATCHED,
            'patches/f.diff' => $patch,
        ])->verify('patches/f.diff');

        $this->assertSame(PatchVerifier::VERIFIED, $result['state']);
    }

    public function testACustomisedOrMissingFileCannotBeJudged(): void
    {
        $result = $this->verifier([
            self::TARGET => "<?php\nclass Escaper {}\n",
            'patches/a.patch' => self::COMPOSER_PATCH,
        ])->verify('patches/a.patch');
        $this->assertSame(PatchVerifier::UNVERIFIABLE, $result['state']);

        $missing = $this->verifier(['patches/a.patch' => self::COMPOSER_PATCH])->verify('patches/a.patch');
        $this->assertSame(PatchVerifier::UNVERIFIABLE, $missing['state']);
        $this->assertNull($missing['files'][0]['resolved']);

        $unreadable = $this->verifier([])->verify('patches/gone.patch');
        $this->assertSame(PatchVerifier::UNVERIFIABLE, $unreadable['state']);
        $this->assertSame('patch file absent', $unreadable['reason']);
    }

    public function testANewFileIsAppliedOnlyOnceItExists(): void
    {
        $patch = <<<'DIFF'
            --- /dev/null
            +++ b/vendor/magento/module-csp/etc/new.xml
            @@ -0,0 +1,2 @@
            +<config>
            +</config>
            DIFF;

        $this->assertSame(
            PatchVerifier::NOT_APPLIED,
            $this->verifier(['p.patch' => $patch])->verify('p.patch')['state']
        );
        $this->assertSame(
            PatchVerifier::VERIFIED,
            $this->verifier(['p.patch' => $patch, 'vendor/magento/module-csp/etc/new.xml' => "<config>\n</config>\n"])
                ->verify('p.patch')['state']
        );
    }

    public function testAHunkALaterPatchRewroteStillCountsAsApplied(): void
    {
        $later = <<<'DIFF'
            --- a/vendor/magento/module-catalog-inventory/Model/Escaper.php
            +++ b/vendor/magento/module-catalog-inventory/Model/Escaper.php
            @@ -5,3 +5,3 @@
                 {
            -        return htmlspecialchars($value, ENT_QUOTES);
            +        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE);
                 }
            DIFF;
        $code = str_replace('ENT_QUOTES)', 'ENT_QUOTES | ENT_SUBSTITUTE)', self::PATCHED);
        $files = [self::TARGET => $code, 'd/jul.diff' => self::COMPOSER_PATCH, 'd/aug.diff' => $later];

        $alone = $this->verifier($files)->verify('d/jul.diff');
        $this->assertSame(PatchVerifier::PARTIAL, $alone['state']);

        $withLater = $this->verifier($files)->verify('d/jul.diff', null, ['d/jul.diff', 'd/aug.diff']);
        $this->assertSame(PatchVerifier::VERIFIED, $withLater['state']);
        $this->assertSame(PatchVerifier::VERIFIED, $this->verifier($files)->verify('d/aug.diff')['state']);
    }

    public function testAnAbsoluteOrClimbingPathIsNeverRead(): void
    {
        $patch = <<<'DIFF'
            --- /etc/passwd
            +++ /etc/passwd
            @@ -1 +1 @@
            -root:x:0:0
            +root:x:0:1
            --- a/../../etc/hosts
            +++ b/../../etc/hosts
            @@ -1 +1 @@
            -a
            +b
            DIFF;
        $read = [];
        $project = $this->createMock(ProjectFiles::class);
        $project->method('vendorPath')->willReturn('vendor');
        $project->method('readText')->willReturnCallback(
            static function (string $path) use ($patch, &$read): array {
                $read[] = $path;

                return ['data' => $path === 'p.patch' ? $patch : "root:x:0:0\n", 'state' => 'read', 'path' => $path];
            }
        );

        $result = (new PatchVerifier($project))->verify('p.patch');

        $this->assertSame(PatchVerifier::UNVERIFIABLE, $result['state']);
        $this->assertSame(['p.patch'], $read);
    }

    public function testATargetOverTheSizeCapIsJudgedUnknown(): void
    {
        $project = $this->createMock(ProjectFiles::class);
        $project->method('vendorPath')->willReturn('vendor');
        $project->method('readText')->willReturnCallback(
            static fn (string $path, int $maxBytes = 0): array => $path === 'a.patch'
                ? ['data' => self::COMPOSER_PATCH, 'state' => 'read', 'path' => $path]
                : ['data' => null, 'state' => $maxBytes <= 4194304 ? 'unreadable' : 'read', 'path' => $path]
        );

        $this->assertSame(PatchVerifier::UNVERIFIABLE, (new PatchVerifier($project))->verify('a.patch')['state']);
    }

    public function testEachPatchIsReadOnceEvenWhenItIsAlsoASibling(): void
    {
        $reads = [];
        $files = [self::TARGET => self::PATCHED, 'd/a.diff' => self::COMPOSER_PATCH, 'd/b.diff' => self::COMPOSER_PATCH];
        $project = $this->createMock(ProjectFiles::class);
        $project->method('vendorPath')->willReturn('vendor');
        $project->method('readText')->willReturnCallback(
            static function (string $path) use ($files, &$reads): array {
                $reads[$path] = ($reads[$path] ?? 0) + 1;

                return ['data' => $files[$path] ?? null, 'state' => isset($files[$path]) ? 'read' : 'absent', 'path' => $path];
            }
        );
        $verifier = new PatchVerifier($project);

        $verifier->verify('d/a.diff', null, ['d/a.diff', 'd/b.diff']);
        $verifier->verify('d/b.diff', null, ['d/a.diff', 'd/b.diff']);

        $this->assertSame(1, $reads['d/a.diff'] ?? 0);
        $this->assertSame(2, $reads['d/b.diff'] ?? 0);
    }

    public function testAComposerPatchesRecordResolvesRelativeToItsPackage(): void
    {
        $relative = str_replace('vendor/magento/module-catalog-inventory/', '', self::COMPOSER_PATCH);

        $result = $this->verifier([self::TARGET => self::PATCHED, 'patches/rel.patch' => $relative])
            ->verify('patches/rel.patch', 'magento/module-catalog-inventory');

        $this->assertSame(PatchVerifier::VERIFIED, $result['state']);
    }

    /**
     * @param array<string, string> $files Path => contents; anything else is absent.
     */
    private function verifier(array $files): PatchVerifier
    {
        $project = $this->createMock(ProjectFiles::class);
        $project->method('vendorPath')->willReturn('vendor');
        $project->method('readText')->willReturnCallback(
            static fn (string $path): array => [
                'data' => $files[$path] ?? null,
                'state' => isset($files[$path]) ? 'read' : 'absent',
                'path' => $path,
            ]
        );

        return new PatchVerifier($project);
    }
}
