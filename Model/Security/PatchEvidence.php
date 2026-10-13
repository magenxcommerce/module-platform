<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

/**
 * What this installation records about the patches applied to it.
 *
 * Adobe's isolated security patches change files without changing a single
 * package version, so a module list cannot tell a patched 2.4.8-p5 from an
 * unpatched one. What *can* tell them apart is whatever applied the patch, and
 * on a composer-built store that leaves a record in one of four places:
 *
 * - `vendor/composer/installed.json` — cweagans/composer-patches 1.x writes
 *   `extra.patches_applied` onto every package it patched. Applied.
 * - `patches.lock.json` — composer-patches 2.x resolves every patch into this
 *   file and applies exactly that set on install. Applied.
 * - `composer.json` `extra.patches` and the file `extra.patches-file` names.
 *   Declared: applied on the next install, which may or may not have run.
 * - `m2-hotfixes/*.patch` — the directory Adobe Commerce Cloud applies on every
 *   build, and a common convention elsewhere. Present.
 *
 * A patch applied by hand with `patch -p1` leaves none of these, which is why
 * the absence of evidence is reported as exactly that and never as "missing".
 */
class PatchEvidence
{
    public const APPLIED = 'applied';
    public const DECLARED = 'declared';
    public const PRESENT = 'present';
    public const NO_EVIDENCE = 'no_evidence';

    private const HOTFIX_DIR = 'm2-hotfixes';

    private ProjectFiles $files;

    /** @var array{sources: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>}|null */
    private ?array $collected = null;

    /**
     * @param ProjectFiles $files
     */
    public function __construct(ProjectFiles $files)
    {
        $this->files = $files;
    }

    /**
     * Every patch record found, and what was looked at to find them.
     *
     * Each entry is `{source, status, package, description, reference}`;
     * each source is `{source, path, state, entries}`, where `state` is
     * `read`, `absent` or `unreadable` — the last being a hardened store
     * keeping the PHP user out, which is worth knowing rather than reading as
     * "no patches".
     *
     * @return array{sources: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>}
     */
    public function collect(): array
    {
        if ($this->collected !== null) {
            return $this->collected;
        }

        $sources = [];
        $entries = [];

        $this->add($sources, $entries, 'composer_patches_v1', $this->files->installedJson(), [
            $this,
            'fromInstalledJson',
        ]);
        $this->add($sources, $entries, 'composer_patches_v2', $this->files->readJson('patches.lock.json'), [
            $this,
            'fromPatchesLock',
        ]);
        $composer = $this->files->readJson('composer.json');
        $this->add($sources, $entries, 'composer_json', $composer, [$this, 'fromComposerJson']);

        $patchesFile = $composer['data']['extra']['patches-file'] ?? null;
        if (is_string($patchesFile) && $patchesFile !== '' && !str_contains($patchesFile, '..')) {
            $declared = $this->files->readJson(ltrim($patchesFile, './'));
            $this->add($sources, $entries, 'composer_patches_file', $declared, [$this, 'fromPatchesFile']);
        }

        $hotfixes = $this->files->listDirectory(self::HOTFIX_DIR);
        $found = [];
        foreach ($hotfixes['files'] as $path) {
            if (str_ends_with(strtolower($path), '.patch')) {
                $name = substr($path, (int) strrpos('/' . $path, '/'));
                $found[] = $this->entry('m2_hotfixes', self::PRESENT, null, $name, $path);
            }
        }
        $sources[] = [
            'source' => 'm2_hotfixes',
            'path' => $hotfixes['path'],
            'state' => $hotfixes['state'],
            'entries' => count($found),
        ];
        array_push($entries, ...$found);

        return $this->collected = ['sources' => $sources, 'entries' => $entries];
    }

    /**
     * The strongest evidence that any of the given ids was applied.
     *
     * An id matches an entry when it appears, case-insensitively, in the
     * entry's description or reference — Adobe names both the patch files and
     * their composer descriptions after the patch (`VULN-39341_248-p3.patch`)
     * or the bulletin. Underscores are read as hyphens so `2026_jul` and
     * `2026-jul` are the same id.
     *
     * @param string[] $ids Patch and bulletin ids, e.g. ["VULN-39341", "APSB26-146"].
     * @return array{status: string, matches: array<int, array<string, mixed>>}
     */
    public function match(array $ids): array
    {
        $needles = array_values(array_filter(array_map(
            static fn ($id): string => is_string($id) ? str_replace('_', '-', strtolower(trim($id))) : '',
            $ids
        )));

        $matches = [];
        foreach ($this->collect()['entries'] as $entry) {
            $haystack = str_replace('_', '-', strtolower($entry['description'] . ' ' . $entry['reference']));
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    $matches[] = $entry;
                    break;
                }
            }
        }

        $status = self::NO_EVIDENCE;
        foreach ([self::APPLIED, self::DECLARED, self::PRESENT] as $candidate) {
            foreach ($matches as $entry) {
                if ($entry['status'] === $candidate) {
                    $status = $candidate;
                    break 2;
                }
            }
        }

        return ['status' => $status, 'matches' => $matches];
    }

    /**
     * Append what one read file yields, and account for the file either way.
     *
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $entries
     * @param string $source
     * @param array{data: array<mixed>|null, state: string, path: string} $file
     * @param callable(array<mixed>): array<int, array<string, mixed>> $extract
     * @return void
     */
    private function add(array &$sources, array &$entries, string $source, array $file, callable $extract): void
    {
        $found = $file['data'] === null ? [] : $extract($file['data']);

        $sources[] = [
            'source' => $source,
            'path' => $file['path'],
            'state' => $file['state'],
            'entries' => count($found),
        ];
        array_push($entries, ...$found);
    }

    /**
     * composer-patches 1.x: `extra.patches_applied` on each installed package.
     *
     * installed.json is `{"packages": [...]}` from Composer 2 and a bare list
     * from Composer 1; both are accepted.
     *
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function fromInstalledJson(array $data): array
    {
        $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;

        $entries = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !is_array($package['extra']['patches_applied'] ?? null)) {
                continue;
            }
            $name = is_string($package['name'] ?? null) ? $package['name'] : null;
            foreach ($package['extra']['patches_applied'] as $description => $reference) {
                $entries[] = $this->entry('composer_patches_v1', self::APPLIED, $name, $description, $reference);
            }
        }

        return $entries;
    }

    /**
     * composer-patches 2.x: `{"patches": {"vendor/package": [{description, url, ...}]}}`.
     *
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function fromPatchesLock(array $data): array
    {
        $entries = [];
        foreach ((array) ($data['patches'] ?? []) as $package => $patches) {
            foreach ((array) $patches as $patch) {
                if (!is_array($patch)) {
                    continue;
                }
                $entries[] = $this->entry(
                    'composer_patches_v2',
                    self::APPLIED,
                    is_string($package) ? $package : null,
                    $patch['description'] ?? '',
                    $patch['url'] ?? ''
                );
            }
        }

        return $entries;
    }

    /**
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function fromComposerJson(array $data): array
    {
        return $this->fromPatchMap($data['extra']['patches'] ?? [], 'composer_json');
    }

    /**
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function fromPatchesFile(array $data): array
    {
        return $this->fromPatchMap($data['patches'] ?? [], 'composer_patches_file');
    }

    /**
     * The declaration format shared by composer.json and a patches file:
     * `{"vendor/package": {"description": "path or url"}}`, or in 2.x a list
     * of `{description, url}` objects under each package.
     *
     * @param mixed $map
     * @param string $source
     * @return array<int, array<string, mixed>>
     */
    private function fromPatchMap(mixed $map, string $source): array
    {
        $entries = [];
        foreach (is_array($map) ? $map : [] as $package => $patches) {
            foreach (is_array($patches) ? $patches : [] as $description => $patch) {
                if (is_array($patch)) {
                    $entries[] = $this->entry(
                        $source,
                        self::DECLARED,
                        (string) $package,
                        $patch['description'] ?? '',
                        $patch['url'] ?? ''
                    );
                    continue;
                }
                $entries[] = $this->entry($source, self::DECLARED, (string) $package, $description, $patch);
            }
        }

        return $entries;
    }

    /**
     * @param string $source
     * @param string $status
     * @param string|null $package
     * @param mixed $description
     * @param mixed $reference
     * @return array<string, mixed>
     */
    private function entry(
        string $source,
        string $status,
        ?string $package,
        mixed $description,
        mixed $reference
    ): array {
        return [
            'source' => $source,
            'status' => $status,
            'package' => $package,
            'description' => is_scalar($description) ? (string) $description : '',
            'reference' => is_scalar($reference) ? (string) $reference : '',
        ];
    }
}
