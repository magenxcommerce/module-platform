<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The composer files at the Magento root, read through Magento's filesystem.
 *
 * Everything the Security and Compatibility tabs know about this installation
 * that Magento does not already hold comes from here: composer.json and
 * composer.lock, composer-patches' records, the installed-packages manifest,
 * the patch files kept on disk and the vendor files those patches change.
 * Nothing is executed — `composer audit`, `magento-patches status` and Adobe's
 * `patch-status` all need process execution, which a hardened store disables
 * for the PHP user, and should.
 *
 * Every read reports a state beside its data: `read`, `absent`, or
 * `unreadable`. The last is a hardened store keeping the PHP user out of a
 * file, and it has to reach the admin as exactly that — read as "absent" it
 * would turn into "no patches applied" or "no packages locked".
 */
class ProjectFiles
{
    private const VENDOR_PATH_FILE = 'app/etc/vendor_path.php';

    /** installed.json and composer.lock run to a few megabytes; far past that is not one. */
    private const MAX_FILE_BYTES = 33554432;

    private Filesystem $filesystem;

    private Json $json;

    /** @var array<string, array{data: array<mixed>|null, state: string, path: string}> */
    private array $read = [];

    /**
     * @param Filesystem $filesystem
     * @param Json $json
     */
    public function __construct(Filesystem $filesystem, Json $json)
    {
        $this->filesystem = $filesystem;
        $this->json = $json;
    }

    /**
     * One JSON file, relative to the Magento root.
     *
     * @param string $path
     * @return array{data: array<mixed>|null, state: string, path: string}
     */
    public function readJson(string $path): array
    {
        if (isset($this->read[$path])) {
            return $this->read[$path];
        }

        $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
        $data = null;
        try {
            if (!$root->isFile($path)) {
                $state = 'absent';
            } elseif ((int) ($root->stat($path)['size'] ?? 0) > self::MAX_FILE_BYTES) {
                $state = 'unreadable';
            } else {
                $decoded = $this->json->unserialize((string) $root->readFile($path));
                $data = is_array($decoded) ? $decoded : null;
                $state = $data === null ? 'unreadable' : 'read';
            }
        } catch (\Throwable) {
            // A permission refusal and a malformed file are the same answer to
            // the question asked: nothing usable could be read from here.
            $state = 'unreadable';
        }

        return $this->read[$path] = ['data' => $data, 'state' => $state, 'path' => $path];
    }

    /**
     * One text file, relative to the Magento root, at most $maxBytes long.
     *
     * @param string $path
     * @param int $maxBytes
     * @return array{data: string|null, state: string, path: string}
     */
    public function readText(string $path, int $maxBytes = self::MAX_FILE_BYTES): array
    {
        $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
        $data = null;
        try {
            if (!$root->isFile($path)) {
                $state = 'absent';
            } elseif ((int) ($root->stat($path)['size'] ?? 0) > $maxBytes) {
                $state = 'unreadable';
            } else {
                $data = (string) $root->readFile($path);
                $state = 'read';
            }
        } catch (\Throwable) {
            $state = 'unreadable';
        }

        return ['data' => $data, 'state' => $state, 'path' => $path];
    }

    /**
     * Composer's record of what is installed, wherever vendor/ is.
     *
     * @return array{data: array<mixed>|null, state: string, path: string}
     */
    public function installedJson(): array
    {
        return $this->readJson($this->vendorPath() . '/composer/installed.json');
    }

    /**
     * The files in one directory under the root, as paths relative to it.
     *
     * @param string $directory
     * @return array{files: string[], state: string, path: string}
     */
    public function listDirectory(string $directory): array
    {
        $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
        try {
            if (!$root->isExist($directory)) {
                return ['files' => [], 'state' => 'absent', 'path' => $directory];
            }
            $files = array_values(array_map('strval', $root->read($directory)));
        } catch (\Throwable) {
            return ['files' => [], 'state' => 'unreadable', 'path' => $directory];
        }

        return ['files' => $files, 'state' => 'read', 'path' => $directory];
    }

    /**
     * Where Composer put vendor/, relative to the Magento root.
     *
     * Magento records it in app/etc/vendor_path.php as `return './vendor';`.
     * That file is read as text rather than included: the value is all that is
     * wanted, and there is no reason for a diagnostic to execute anything.
     *
     * @return string
     */
    public function vendorPath(): string
    {
        $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
        try {
            $source = $root->isFile(self::VENDOR_PATH_FILE) ? (string) $root->readFile(self::VENDOR_PATH_FILE) : '';
        } catch (\Throwable) {
            $source = '';
        }

        if (preg_match('/return\s+[\'"]\.?\/?([A-Za-z0-9_.\-\/]+?)\/?[\'"]\s*;/', $source, $match) === 1
            && !str_contains($match[1], '..')
        ) {
            return $match[1];
        }

        return 'vendor';
    }
}
