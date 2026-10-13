<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

use Magenx\Platform\Model\Config;

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
 * - `*.patch` / `*.diff` in the configured patch directories (`patches/`,
 *   `m2-hotfixes/` — what Adobe Commerce Cloud applies on every build — and
 *   `var/patch_metadata/.patch_diffs/`, which `vendor/bin/patch-status` writes,
 *   by default). Present.
 *
 * None of those says what the code holds now. So every patch file that can be
 * read — in a patch directory, or a local file a composer-patches record names
 * — is also checked hunk by hunk against the files it changes
 * ({@see PatchVerifier}), and that answer outranks the record: `verified` when
 * every hunk is in the code, `partial`, or `not_in_code` when the code is still
 * unpatched whatever a record claims. That is what credits a patch applied by
 * hand with `patch -p1`, which leaves no record at all.
 *
 * A patch with no file left on disk and no record is reported as exactly that,
 * never as "missing".
 */
class PatchEvidence
{
    public const VERIFIED = 'verified';
    public const APPLIED = 'applied';
    public const DECLARED = 'declared';
    public const PRESENT = 'present';
    public const PARTIAL = 'partial';
    public const NOT_IN_CODE = 'not_in_code';
    public const NO_EVIDENCE = 'no_evidence';

    private const HOTFIX_DIR = 'm2-hotfixes';

    private const PATCH_EXTENSIONS = ['patch', 'diff'];

    private const MONTHS = [
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
        'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
    ];

    /** What the code says about a patch file, as the evidence it amounts to. */
    private const CODE_EVIDENCE = [
        PatchVerifier::VERIFIED => self::VERIFIED,
        PatchVerifier::PARTIAL => self::PARTIAL,
        PatchVerifier::NOT_APPLIED => self::NOT_IN_CODE,
    ];

    private ProjectFiles $files;

    private PatchVerifier $verifier;

    private Config $config;

    /** @var array{sources: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>}|null */
    private ?array $collected = null;

    /**
     * @param ProjectFiles $files
     * @param PatchVerifier $verifier
     * @param Config $config
     */
    public function __construct(ProjectFiles $files, PatchVerifier $verifier, Config $config)
    {
        $this->files = $files;
        $this->verifier = $verifier;
        $this->config = $config;
    }

    /**
     * Every patch record found, and what was looked at to find them.
     *
     * Each entry is `{source, status, package, description, reference, code}`,
     * `code` being the {@see PatchVerifier} result when the entry names a patch
     * file that could be read, and null otherwise;
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

        foreach ($this->config->getPatchDirs() as $directory) {
            $source = $directory === self::HOTFIX_DIR ? 'm2_hotfixes' : 'patch_dir';
            $listing = $this->files->listFiles($directory);
            $found = [];
            foreach ($listing['files'] as $path) {
                if ($this->isPatchFile($path)) {
                    $name = substr($path, (int) strrpos('/' . $path, '/'));
                    $found[] = $this->entry($source, self::PRESENT, null, $name, $path);
                }
            }
            $sources[] = [
                'source' => $source,
                'path' => $listing['path'],
                'state' => $listing['state'],
                'entries' => count($found),
            ];
            array_push($entries, ...$found);
        }

        foreach ($entries as $index => $entry) {
            $entries[$index] = $this->checkCode($entry);
        }

        return $this->collected = ['sources' => $sources, 'entries' => $entries];
    }

    /**
     * The strongest evidence that any of the given ids was applied.
     *
     * Where the code could be checked, the code decides: `verified` if any
     * matching patch file is wholly in it, else `partial`, else `not_in_code`.
     * Records only decide when no matching patch file could be judged.
     *
     * An id matches an entry when it appears, case-insensitively, in the
     * entry's description or reference — Adobe names both the patch files and
     * their composer descriptions after the patch (`VULN-39341_248-p3.patch`)
     * or the bulletin. Underscores are read as hyphens so `2026_jul` and
     * `2026-jul` are the same id, and a monthly patch is also found by its
     * numeric month: `2026-jul` matches `248p5-2026-07-001-CE.diff`.
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

        $months = [];
        foreach ($needles as $needle) {
            if (preg_match('/^(\d{4})-([a-z]{3})$/', $needle, $month) === 1 && isset(self::MONTHS[$month[2]])) {
                $months[] = sprintf('/(?<![0-9])%s-%s(?![0-9])/', $month[1], self::MONTHS[$month[2]]);
            }
        }

        $matches = [];
        foreach ($this->collect()['entries'] as $entry) {
            $haystack = str_replace('_', '-', strtolower($entry['description'] . ' ' . $entry['reference']));
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    $matches[] = $entry;
                    continue 2;
                }
            }
            foreach ($months as $pattern) {
                if (preg_match($pattern, $haystack) === 1) {
                    $matches[] = $entry;
                    continue 2;
                }
            }
        }

        $status = self::NO_EVIDENCE;
        foreach ([self::VERIFIED, self::PARTIAL, self::NOT_IN_CODE, self::APPLIED, self::DECLARED, self::PRESENT]
            as $candidate
        ) {
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
     * Check the patch file an entry names against the code, when there is one
     * on disk to read. A URL is left alone: fetching patches is not this
     * module's business.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function checkCode(array $entry): array
    {
        $reference = ltrim($entry['reference'], './');
        if ($reference === ''
            || str_contains($reference, '://')
            || str_contains($reference, '..')
            || !$this->isPatchFile($reference)
        ) {
            return $entry;
        }

        $entry['code'] = $this->verifier->verify($reference, $entry['package']);
        if (isset(self::CODE_EVIDENCE[$entry['code']['state']])) {
            $entry['record_status'] = $entry['status'];
            $entry['status'] = self::CODE_EVIDENCE[$entry['code']['state']];
        }

        return $entry;
    }

    /**
     * @param string $path
     * @return bool
     */
    private function isPatchFile(string $path): bool
    {
        $dot = strrpos($path, '.');

        return $dot !== false && in_array(strtolower(substr($path, $dot + 1)), self::PATCH_EXTENSIONS, true);
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
            'code' => null,
        ];
    }
}
