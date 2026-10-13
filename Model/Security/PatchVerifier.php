<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

/**
 * Whether a patch file's changes are in the code, read from the code itself.
 *
 * A record says a patch was applied; only the files it changes can say it
 * still is. Each hunk of the unified diff is looked for in the file it targets:
 * its after-image (context and added lines) found there means applied, its
 * before-image (context and removed lines) means not applied, and neither means
 * the file was changed some other way. Lines are compared trimmed, so a
 * reindent or CRLF checkout does not read as a missing patch. Nothing is
 * executed: this is `patch --dry-run -R` without the process.
 *
 * Adobe ships each isolated patch in two layouts, and both are resolved: the
 * composer one names `vendor/magento/module-catalog/...`, the git one names
 * `app/code/Magento/Catalog/...` and `lib/internal/Magento/Framework/...`,
 * which on a composer-built store live under vendor/ as well. A composer-patches
 * record knows its package, so its paths are also tried relative to that
 * package's directory.
 *
 * Monthly patches build on each other: a later one may rewrite lines an
 * earlier one added, so the earlier one's after-image is gone even though it
 * was applied. Given the other patches kept beside it, a hunk whose after-image
 * is the before-image of a sibling hunk found applied counts as applied — what
 * Adobe's patch-status does by reverting the later patches in a scratch copy.
 */
class PatchVerifier
{
    public const VERIFIED = 'verified';
    public const NOT_APPLIED = 'not_applied';
    public const PARTIAL = 'partial';
    public const UNVERIFIABLE = 'unverifiable';

    private const HUNK_APPLIED = 'applied';
    private const HUNK_NOT_APPLIED = 'not_applied';
    private const HUNK_UNKNOWN = 'unknown';

    /** Adobe's largest isolated patches are a few hundred kilobytes. */
    private const MAX_PATCH_BYTES = 2097152;

    /**
     * Every target file read stays in memory for the request, so a patch that
     * touches a large minified asset is judged unknown rather than read whole.
     */
    private const MAX_TARGET_BYTES = 4194304;

    /** Framework libraries that are packages of their own, not part of magento/framework. */
    private const FRAMEWORK_PACKAGES = ['Amqp', 'Bulk', 'MessageQueue'];

    private ProjectFiles $files;

    /** @var array<string, string[]|null> Resolved path => trimmed lines, null when unreadable. */
    private array $lines = [];

    /** @var array<string, array<string, mixed>> */
    private array $verified = [];

    /** @var array<string, array<int, array<string, mixed>>> Patch path => parsed diff, [] when unreadable. */
    private array $parsed = [];

    /**
     * @param ProjectFiles $files
     */
    public function __construct(ProjectFiles $files)
    {
        $this->files = $files;
    }

    /**
     * Check one patch file, relative to the Magento root, against the code.
     *
     * Returns `{state, hunks, applied, not_applied, files}`, where `state` is
     * `verified` (every hunk in the code), `not_applied` (none in the code, and
     * at least one found unpatched), `partial`, or `unverifiable` (the patch
     * could not be read, or not one target file could be judged), and each file
     * is `{path, resolved, hunks, applied, not_applied}`.
     *
     * @param string $patchPath
     * @param string|null $package The composer package a composer-patches record applies it to.
     * @param string[] $siblings Other patch files kept with it, which may have built on it.
     * @return array<string, mixed>
     */
    public function verify(string $patchPath, ?string $package = null, array $siblings = []): array
    {
        $siblings = array_values(array_diff($siblings, [$patchPath]));
        $key = $patchPath . '|' . $package . '|' . implode(',', $siblings);
        if (isset($this->verified[$key])) {
            return $this->verified[$key];
        }

        $patch = $this->files->readText($patchPath, self::MAX_PATCH_BYTES);
        if ($patch['data'] === null) {
            return $this->verified[$key] = $this->result([], 'patch file ' . $patch['state']);
        }

        $later = [];
        foreach ($siblings as $sibling) {
            foreach ($this->parsedFile($sibling) as $diff) {
                $later[(string) ($diff['new_path'] ?? $diff['old_path'])][] = $diff['hunks'];
            }
        }

        $this->parsed[$patchPath] = $this->parse($patch['data']);
        $files = [];
        foreach ($this->parsed[$patchPath] as $diff) {
            $path = (string) ($diff['new_path'] ?? $diff['old_path']);
            $files[] = $this->verifyFile($diff, $package, array_merge([], ...($later[$path] ?? [])));
        }

        return $this->verified[$key] = $this->result($files, $files === [] ? 'no hunks found' : null);
    }

    /**
     * A unified diff as `[{old_path, new_path, hunks: [{old: string[], new: string[]}]}]`,
     * lines trimmed; `/dev/null` paths are null.
     *
     * @param string $diff
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $diff): array
    {
        $files = [];
        $current = null;
        $hunk = null;
        $remainingOld = 0;
        $remainingNew = 0;

        foreach (preg_split('/\r\n|\r|\n/', $diff) ?: [] as $line) {
            if ($hunk !== null && ($remainingOld > 0 || $remainingNew > 0)) {
                $marker = $line === '' ? ' ' : $line[0];
                $text = trim(substr($line, 1));
                if ($marker === ' ') {
                    $hunk['old'][] = $text;
                    $hunk['new'][] = $text;
                    $remainingOld--;
                    $remainingNew--;
                    continue;
                }
                if ($marker === '-') {
                    $hunk['old'][] = $text;
                    $remainingOld--;
                    continue;
                }
                if ($marker === '+') {
                    $hunk['new'][] = $text;
                    $remainingNew--;
                    continue;
                }
                if ($marker === '\\') {
                    continue;
                }
            }
            if ($hunk !== null) {
                $current['hunks'][] = $hunk;
                $hunk = null;
            }

            if (str_starts_with($line, '--- ')) {
                if ($current !== null && $current['hunks'] !== []) {
                    $files[] = $current;
                }
                $current = ['old_path' => $this->diffPath(substr($line, 4)), 'new_path' => null, 'hunks' => []];
                continue;
            }
            if ($current !== null && str_starts_with($line, '+++ ')) {
                $current['new_path'] = $this->diffPath(substr($line, 4));
                continue;
            }
            if ($current !== null
                && preg_match('/^@@ -\d+(?:,(\d+))? \+\d+(?:,(\d+))? @@/', $line, $range) === 1
            ) {
                $remainingOld = isset($range[1]) && $range[1] !== '' ? (int) $range[1] : 1;
                $remainingNew = isset($range[2]) && $range[2] !== '' ? (int) $range[2] : 1;
                $hunk = ['old' => [], 'new' => []];
            }
        }
        if ($hunk !== null) {
            $current['hunks'][] = $hunk;
        }
        if ($current !== null && $current['hunks'] !== []) {
            $files[] = $current;
        }

        return $files;
    }

    /**
     * A sibling patch, parsed once.
     *
     * @param string $patchPath
     * @return array<int, array<string, mixed>>
     */
    private function parsedFile(string $patchPath): array
    {
        if (!isset($this->parsed[$patchPath])) {
            $patch = $this->files->readText($patchPath, self::MAX_PATCH_BYTES);
            $this->parsed[$patchPath] = $patch['data'] === null ? [] : $this->parse($patch['data']);
        }

        return $this->parsed[$patchPath];
    }

    /**
     * @param array<string, mixed> $diff
     * @param string|null $package
     * @param array<int, array{old: string[], new: string[]}> $later Sibling hunks on the same file.
     * @return array<string, mixed>
     */
    private function verifyFile(array $diff, ?string $package, array $later = []): array
    {
        $path = $diff['new_path'] ?? $diff['old_path'];
        $resolved = null;
        $lines = null;
        foreach ($this->candidates((string) $path, $package) as $candidate) {
            $lines = $this->lines($candidate);
            if ($lines !== null) {
                $resolved = $candidate;
                break;
            }
        }

        $applied = 0;
        $notApplied = 0;
        foreach ($diff['hunks'] as $hunk) {
            $state = $this->hunkState($hunk, $lines, $diff['old_path'] === null, $diff['new_path'] === null);
            if ($state === self::HUNK_UNKNOWN && $lines !== null && $this->superseded($hunk, $later, $lines)) {
                $state = self::HUNK_APPLIED;
            }
            $applied += (int) ($state === self::HUNK_APPLIED);
            $notApplied += (int) ($state === self::HUNK_NOT_APPLIED);
        }

        return [
            'path' => $path,
            'resolved' => $resolved,
            'hunks' => count($diff['hunks']),
            'applied' => $applied,
            'not_applied' => $notApplied,
        ];
    }

    /**
     * @param array{old: string[], new: string[]} $hunk
     * @param string[]|null $lines The target file, null when it is not there.
     * @param bool $creates The patch creates this file.
     * @param bool $deletes The patch deletes this file.
     * @return string
     */
    private function hunkState(array $hunk, ?array $lines, bool $creates, bool $deletes): string
    {
        if ($lines === null) {
            if ($creates) {
                return self::HUNK_NOT_APPLIED;
            }

            return $deletes ? self::HUNK_APPLIED : self::HUNK_UNKNOWN;
        }

        $old = $this->significant($hunk['old']);
        $new = $this->significant($hunk['new']);
        if ($old === $new) {
            return self::HUNK_UNKNOWN;
        }

        $hasOld = $old !== [] && $this->contains($lines, $old);
        $hasNew = $new !== [] && $this->contains($lines, $new);

        if ($hasNew && !$hasOld) {
            return self::HUNK_APPLIED;
        }
        if ($hasOld && !$hasNew) {
            return self::HUNK_NOT_APPLIED;
        }
        if ($deletes && $new === [] && !$hasOld) {
            return self::HUNK_APPLIED;
        }

        return self::HUNK_UNKNOWN;
    }

    /**
     * Whether this hunk is in the code once the later patches found applied
     * are taken back out of a copy of it — the same question patch-status
     * answers by reverting them in a scratch directory.
     *
     * @param array{old: string[], new: string[]} $hunk
     * @param array<int, array{old: string[], new: string[]}> $later
     * @param string[] $lines
     * @return bool
     */
    private function superseded(array $hunk, array $later, array $lines): bool
    {
        $new = $this->significant($hunk['new']);
        if ($new === [] || $later === []) {
            return false;
        }

        $reverted = false;
        foreach ($later as $candidate) {
            $before = $this->significant($candidate['old']);
            $after = $this->significant($candidate['new']);
            $at = $after === [] || $before === $after ? null : $this->indexOf($lines, $after);
            if ($at !== null) {
                array_splice($lines, $at, count($after), $before);
                $reverted = true;
            }
        }

        return $reverted && $this->contains($lines, $new);
    }

    /**
     * Blank lines dropped: patch tools and editors disagree about them more
     * than about anything else, and they never carry a fix.
     *
     * @param string[] $lines
     * @return string[]
     */
    private function significant(array $lines): array
    {
        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * Whether $needle appears in $haystack as consecutive lines.
     *
     * @param string[] $haystack
     * @param string[] $needle
     * @return bool
     */
    private function contains(array $haystack, array $needle): bool
    {
        return $this->indexOf($haystack, $needle) !== null;
    }

    /**
     * Where $needle first appears in $haystack as consecutive lines.
     *
     * @param string[] $haystack
     * @param string[] $needle
     * @return int|null
     */
    private function indexOf(array $haystack, array $needle): ?int
    {
        $length = count($needle);
        $last = count($haystack) - $length;
        // array_keys() finds the candidate first lines in one C-level pass;
        // only those are compared line by line.
        foreach (array_keys($haystack, $needle[0], true) as $start) {
            if ($start > $last) {
                break;
            }
            for ($offset = 1; $offset < $length && $haystack[$start + $offset] === $needle[$offset]; $offset++);
            if ($offset === $length) {
                return $start;
            }
        }

        return null;
    }

    /**
     * Significant, trimmed lines of one file under the root, or null when it
     * is not there to be read.
     *
     * @param string $path
     * @return string[]|null
     */
    private function lines(string $path): ?array
    {
        if (!array_key_exists($path, $this->lines)) {
            $file = $this->files->readText($path, self::MAX_TARGET_BYTES);
            $this->lines[$path] = $file['data'] === null ? null : $this->significant(array_map(
                'trim',
                preg_split('/\r\n|\r|\n/', $file['data']) ?: []
            ));
        }

        return $this->lines[$path];
    }

    /**
     * Where a path named in a patch may live on this store, most specific first.
     *
     * @param string $path
     * @param string|null $package
     * @return string[]
     */
    private function candidates(string $path, ?string $package): array
    {
        // Paths come from the patch file: only relative ones inside the root.
        if ($path === '' || $path[0] === '/' || str_contains($path, '..')) {
            return [];
        }

        $vendor = $this->files->vendorPath();
        $candidates = [];
        if ($package !== null && preg_match('#^[a-z0-9_.\-]+/[a-z0-9_.\-]+$#', $package) === 1) {
            $candidates[] = $vendor . '/' . $package . '/' . $path;
        }

        if (str_starts_with($path, 'vendor/')) {
            $candidates[] = $vendor . substr($path, strlen('vendor'));
        } elseif (preg_match('#^app/code/Magento/([A-Za-z0-9]+)/(.+)$#', $path, $match) === 1) {
            $candidates[] = sprintf('%s/magento/module-%s/%s', $vendor, $this->kebab($match[1]), $match[2]);
        } elseif (preg_match('#^lib/internal/Magento/Framework/(([A-Za-z0-9]+)/.+)$#', $path, $match) === 1) {
            if (in_array($match[2], self::FRAMEWORK_PACKAGES, true)) {
                $candidates[] = sprintf(
                    '%s/magento/framework-%s/%s',
                    $vendor,
                    $this->kebab($match[2]),
                    substr($match[1], strlen($match[2]) + 1)
                );
            }
            $candidates[] = sprintf('%s/magento/framework/%s', $vendor, $match[1]);
        } elseif (preg_match('#^app/design/(frontend|adminhtml)/Magento/([A-Za-z0-9_\-]+)/(.+)$#', $path, $match) === 1) {
            $candidates[] = sprintf('%s/magento/theme-%s-%s/%s', $vendor, $match[1], strtolower($match[2]), $match[3]);
        }
        $candidates[] = $path;

        return array_values(array_unique($candidates));
    }

    /**
     * A diff header's path with its timestamp and a/ or b/ prefix removed.
     *
     * @param string $header
     * @return string|null
     */
    private function diffPath(string $header): ?string
    {
        $path = trim(explode("\t", $header, 2)[0]);
        if ($path === '/dev/null' || $path === '') {
            return null;
        }

        return preg_replace('#^[ab]/#', '', $path);
    }

    /**
     * Magento's module directory name as its composer package spells it:
     * `CatalogGraphQl` is `catalog-graph-ql`.
     *
     * @param string $name
     * @return string
     */
    private function kebab(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param string|null $reason
     * @return array<string, mixed>
     */
    private function result(array $files, ?string $reason): array
    {
        $hunks = array_sum(array_column($files, 'hunks'));
        $applied = array_sum(array_column($files, 'applied'));
        $notApplied = array_sum(array_column($files, 'not_applied'));

        if ($hunks > 0 && $applied === $hunks) {
            $state = self::VERIFIED;
        } elseif ($applied === 0 && $notApplied > 0) {
            $state = self::NOT_APPLIED;
        } elseif ($applied > 0) {
            $state = self::PARTIAL;
        } else {
            $state = self::UNVERIFIABLE;
            $reason ??= 'no changed file could be judged';
        }

        $result = [
            'state' => $state,
            'hunks' => $hunks,
            'applied' => $applied,
            'not_applied' => $notApplied,
            'files' => $files,
        ];
        if ($reason !== null) {
            $result['reason'] = $reason;
        }

        return $result;
    }
}
