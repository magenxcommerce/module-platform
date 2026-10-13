<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework\Security;

use Magenx\Platform\Model\Security\ProjectFiles;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A file the PHP user may not read is "unreadable", never "absent": read as
 * absent it would turn into "no patches" or "no packages".
 */
#[CoversClass(ProjectFiles::class)]
class ProjectFilesTest extends TestCase
{
    public function testStatesAreToldApart(): void
    {
        $files = $this->files([
            'composer.json' => '{"require":{"magento/product-community-edition":"2.4.8-p5"}}',
            'composer.lock' => new FileSystemException(new Phrase('Permission denied')),
            'broken.json' => '{not json',
        ]);

        $this->assertSame('read', $files->readJson('composer.json')['state']);
        $this->assertSame('unreadable', $files->readJson('composer.lock')['state']);
        $this->assertSame('unreadable', $files->readJson('broken.json')['state']);
        $this->assertSame('absent', $files->readJson('patches.lock.json')['state']);
    }

    public function testPatchFilesAreReadAsTextWithinTheirSizeLimit(): void
    {
        $files = $this->files([
            'var/patch_metadata/.patch_diffs/248p5-2026-09-001-CE.diff' => "--- a/x\n",
            'm2-hotfixes/locked.patch' => new FileSystemException(new Phrase('Permission denied')),
        ]);

        $this->assertSame("--- a/x\n", $files->readText('var/patch_metadata/.patch_diffs/248p5-2026-09-001-CE.diff')['data']);
        $this->assertSame('unreadable', $files->readText('m2-hotfixes/locked.patch')['state']);
        $this->assertSame('unreadable', $files->readText('var/patch_metadata/.patch_diffs/248p5-2026-09-001-CE.diff', 10)['state']);
        $this->assertSame('absent', $files->readText('m2-hotfixes/none.patch')['state']);
    }

    public function testVendorPathIsReadAsTextAndCannotClimbOut(): void
    {
        $this->assertSame('lib/vendor', $this->files(['app/etc/vendor_path.php' => "<?php\nreturn './lib/vendor';\n"])->vendorPath());
        $this->assertSame('vendor', $this->files(['app/etc/vendor_path.php' => "<?php\nreturn '../../etc';\n"])->vendorPath());
        $this->assertSame('vendor', $this->files([])->vendorPath());
    }

    /**
     * @param array<string, string|\Throwable> $contents
     */
    private function files(array $contents): ProjectFiles
    {
        $root = $this->createMock(ReadInterface::class);
        $root->method('isFile')->willReturnCallback(static fn (string $path): bool => isset($contents[$path]));
        $root->method('stat')->willReturn(['size' => 100]);
        $root->method('readFile')->willReturnCallback(static function (string $path) use ($contents): string {
            if ($contents[$path] instanceof \Throwable) {
                throw $contents[$path];
            }

            return $contents[$path];
        });

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($root);

        return new ProjectFiles($filesystem, new Json());
    }
}
