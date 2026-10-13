<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

/**
 * Which security issues are open against this store, and what it records
 * about closing them.
 *
 * Two kinds of finding, kept apart because they rest on different evidence:
 *
 * - **The release.** Adobe's bulletins come from magento.watch and are facts
 *   about 2.4.8-p5, whoever runs it. Each is joined with what this
 *   installation records about the isolated patch that closes it — and, where
 *   the patch file is on disk, with whether its changes are in the code — and
 *   every bulletin carries the records that moved it. "no_evidence" is never
 *   written as "missing", because a patch applied by hand leaves none. An
 *   isolated patch Adobe did not publish for this release does not close the
 *   bulletin here unless the code shows it applied: the release has to move.
 * - **The locked packages.** Advisories from Packagist's database, checked
 *   against composer.lock: what `composer audit` would say.
 *
 * Either half failing — a lookup switched off, a host unreachable, a file the
 * PHP user may not read — costs that half and says why. It never costs the
 * report.
 */
class SecurityReport
{
    private const MAX_RECORDS = 100;

    private const MAX_CHECKED_FILES = 100;

    /** Bulletin-level outcome of joining a bulletin with local evidence, strongest first. */
    private const BULLETIN_STATUS = [
        PatchEvidence::VERIFIED => 'verified_in_code',
        PatchEvidence::APPLIED => 'patched',
        PatchEvidence::DECLARED => 'declared_not_verified',
        PatchEvidence::PRESENT => 'hotfix_file_present',
        PatchEvidence::NO_EVIDENCE => 'no_evidence',
        PatchEvidence::PARTIAL => 'partially_applied',
        PatchEvidence::NOT_IN_CODE => 'not_in_code',
    ];

    /** Evidence that closes an isolated patch, so it leaves the apply order. */
    private const CLOSED = [PatchEvidence::VERIFIED, PatchEvidence::APPLIED];

    private InstalledRelease $installedRelease;

    private MagentoWatch $magentoWatch;

    private PatchEvidence $patchEvidence;

    private ComposerProject $project;

    private PackageAdvisories $packageAdvisories;

    /**
     * @param InstalledRelease $installedRelease
     * @param MagentoWatch $magentoWatch
     * @param PatchEvidence $patchEvidence
     * @param ComposerProject $project
     * @param PackageAdvisories $packageAdvisories
     */
    public function __construct(
        InstalledRelease $installedRelease,
        MagentoWatch $magentoWatch,
        PatchEvidence $patchEvidence,
        ComposerProject $project,
        PackageAdvisories $packageAdvisories
    ) {
        $this->installedRelease = $installedRelease;
        $this->magentoWatch = $magentoWatch;
        $this->patchEvidence = $patchEvidence;
        $this->project = $project;
        $this->packageAdvisories = $packageAdvisories;
    }

    /**
     * @param string|null $distribution Overrides the one detected from the edition.
     * @param bool $listRecords Also return every patch record, not only matched ones.
     * @return array<string, mixed>
     * @throws \Magento\Framework\Exception\LocalizedException When Magento cannot name its release.
     */
    public function build(?string $distribution = null, bool $listRecords = false): array
    {
        $release = $this->installedRelease->get();
        $distribution = $distribution ?? $release['distribution'];

        $records = $this->patchEvidence->collect();
        $report = [
            'installed' => ['distribution' => $distribution] + $release,
            'advisories' => ['available' => false, 'source' => 'magento.watch'],
            'status' => 'unknown',
            'open_bulletins' => [],
            'apply_order' => [],
            'patch_records' => [
                'sources' => $records['sources'],
                'total' => count($records['entries']),
                'checked' => $this->checkedFiles($records['entries']),
            ],
        ];
        if ($listRecords) {
            $report['patch_records']['entries'] = array_slice($records['entries'], 0, self::MAX_RECORDS);
        }
        $report['package_advisories'] = $this->packageAdvisories();

        $lookup = $this->magentoWatch->getVersion($distribution, $release['version']);
        if ($lookup === null) {
            $report['advisories']['reason'] = $this->magentoWatch->getLastError();
            $report['summary'] = sprintf(
                '%s %s. Bulletins unavailable (%s); %d local patch record(s) found.',
                $distribution,
                $release['version'],
                $this->magentoWatch->getLastError(),
                count($records['entries'])
            );

            return $report;
        }

        $data = $lookup['data'];
        $security = is_array($data['security'] ?? null) ? $data['security'] : [];
        $report['advisories'] = [
            'available' => true,
            'source' => 'magento.watch',
            'fetched_at' => $lookup['fetched_at'],
            'cached' => $lookup['cached'],
            'release_status' => $security['status'] ?? null,
        ];
        $report['lifecycle'] = [
            'release_date' => $data['releaseDate'] ?? null,
            'eol_date' => $data['eolDate'] ?? null,
            'status_label' => $data['statusLabel'] ?? null,
            'is_eol' => (bool) ($data['isEOLVersion'] ?? false),
        ];

        $bulletins = [];
        foreach ((array) ($security['vulnerableTo'] ?? []) as $bulletin) {
            if (is_array($bulletin)) {
                $bulletins[] = $this->joinBulletin($bulletin, $release['version']);
            }
        }

        $report['open_bulletins'] = $bulletins;
        $report['apply_order'] = $this->applyOrder($bulletins);
        $report['recommended_version'] = $security['recommendedVersion'] ?? null;
        $report['fixed_by_installed_release'] = array_values(array_filter(array_map(
            static fn ($fixed): ?string => is_array($fixed) && is_string($fixed['id'] ?? null) ? $fixed['id'] : null,
            (array) ($security['fixedBy'] ?? [])
        )));
        $report['status'] = $this->overallStatus($bulletins, $report['lifecycle']['is_eol']);
        $report['summary'] = $this->summarize($release['version'], $report['status'], $bulletins)
            . $this->packageSummary($report['package_advisories']);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function packageAdvisories(): array
    {
        $locked = $this->project->lockedPackages();
        if ($locked['packages'] === []) {
            return [
                'available' => false,
                'reason' => sprintf('%s is %s', $locked['path'], $locked['state']),
                'checked' => 0,
                'vulnerable' => [],
            ];
        }

        return $this->packageAdvisories->check($locked['packages']);
    }

    /**
     * Every patch file whose code was checked, and what the code said.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function checkedFiles(array $entries): array
    {
        $checked = [];
        foreach ($entries as $entry) {
            if (!is_array($entry['code'] ?? null)) {
                continue;
            }
            $checked[$entry['reference'] . '|' . $entry['package']] = [
                'path' => $entry['reference'],
                'source' => $entry['source'],
                'package' => $entry['package'],
                'state' => $entry['code']['state'],
                'hunks' => $entry['code']['hunks'],
                'applied' => $entry['code']['applied'],
                'not_applied' => $entry['code']['not_applied'],
                'reason' => $entry['code']['reason'] ?? null,
            ];
        }

        return array_slice(array_values($checked), 0, self::MAX_CHECKED_FILES);
    }

    /**
     * One open bulletin, with the evidence for each of its isolated patches.
     *
     * @param array<string, mixed> $bulletin
     * @param string $version The installed release.
     * @return array<string, mixed>
     */
    private function joinBulletin(array $bulletin, string $version): array
    {
        $id = (string) ($bulletin['id'] ?? '');
        $patches = [];
        $weakest = null;
        $upgradeTo = null;

        foreach ((array) ($bulletin['isolatedPatches'] ?? []) as $patch) {
            if (!is_array($patch) || !is_string($patch['id'] ?? null)) {
                continue;
            }
            // The bulletin id is matched as well as the patch id: a patch file
            // saved as "APSB26-73_2.4.8-p5.patch" is evidence for that bulletin
            // even though it never names "2026-jul".
            $match = $this->patchEvidence->match(
                [$patch['id'], $id],
                array_values(array_filter((array) ($bulletin['cves'] ?? []), 'is_string'))
            );
            $appliesTo = array_values(array_filter((array) ($patch['appliesTo'] ?? []), 'is_string'));
            $applies = $appliesTo === [] || in_array($version, $appliesTo, true);
            $requires = $applies ? null : $this->sameLine($version, $appliesTo);
            $patches[] = [
                'id' => $patch['id'],
                'url' => $patch['url'] ?? null,
                'evidence' => $match['status'],
                'applies_to_installed' => $applies,
                'requires_release' => $requires,
                'matched_records' => array_map(
                    static fn (array $entry): array => [
                        'source' => $entry['source'],
                        'package' => $entry['package'],
                        'description' => $entry['description'],
                        'patch_id' => $entry['patch_id'] ?? null,
                        'code' => is_array($entry['code'] ?? null) ? [
                            'state' => $entry['code']['state'],
                            'hunks' => $entry['code']['hunks'],
                            'applied' => $entry['code']['applied'],
                        ] : null,
                    ],
                    $match['matches']
                ),
            ];
            $weakest = $this->weaker($weakest, $match['status']);
            if (!$applies && $match['status'] !== PatchEvidence::VERIFIED) {
                $upgradeTo ??= $requires ?? ($appliesTo[0] ?? null);
            }
        }

        if ($patches === []) {
            $status = ($bulletin['fixedIn'] ?? null) !== null ? 'upgrade_required' : 'no_fix_published';
        } elseif ($upgradeTo !== null) {
            $status = 'upgrade_required';
        } else {
            $status = self::BULLETIN_STATUS[$weakest ?? PatchEvidence::NO_EVIDENCE];
        }

        return [
            'id' => $id,
            'severity' => $bulletin['severity'] ?? null,
            'published_date' => $bulletin['publishedDate'] ?? null,
            'cves' => array_values((array) ($bulletin['cves'] ?? [])),
            'fixed_in' => $bulletin['fixedIn'] ?? null,
            'upgrade_to' => $upgradeTo,
            'isolated_patches' => $patches,
            'local_status' => $status,
        ];
    }

    /**
     * The isolated patches not yet recorded or verified as applied, oldest
     * bulletin first. A patch not published for this release is left out: it
     * goes on after the upgrade its bulletin names, not before.
     *
     * The API lists bulletins newest first. Adobe's isolated patches are not
     * cumulative and are meant to go on in publication order, so the order is
     * reversed here rather than left for a reader to work out.
     *
     * @param array<int, array<string, mixed>> $bulletins
     * @return string[]
     */
    private function applyOrder(array $bulletins): array
    {
        $order = [];
        foreach (array_reverse($bulletins) as $bulletin) {
            foreach ($bulletin['isolated_patches'] as $patch) {
                if (!in_array($patch['evidence'], self::CLOSED, true)
                    && $patch['applies_to_installed']
                    && !in_array($patch['id'], $order, true)
                ) {
                    $order[] = $patch['id'];
                }
            }
        }

        return $order;
    }

    /**
     * @param array<int, array<string, mixed>> $bulletins
     * @param bool $isEol
     * @return string
     */
    private function overallStatus(array $bulletins, bool $isEol): string
    {
        if ($isEol) {
            return 'eol';
        }
        if ($bulletins === []) {
            return 'secure';
        }

        $statuses = array_column($bulletins, 'local_status');
        if (array_diff($statuses, ['verified_in_code']) === []) {
            return 'patched_verified';
        }
        if (array_diff($statuses, ['verified_in_code', 'patched']) === []) {
            return 'patched_per_local_records';
        }
        if (array_diff(
            $statuses,
            ['verified_in_code', 'patched', 'declared_not_verified', 'hotfix_file_present']
        ) === []) {
            return 'possibly_patched';
        }

        return 'vulnerable';
    }

    /**
     * @param string $version
     * @param string $status
     * @param array<int, array<string, mixed>> $bulletins
     * @return string
     */
    private function summarize(string $version, string $status, array $bulletins): string
    {
        $open = array_filter(
            $bulletins,
            static fn (array $bulletin): bool => !in_array(
                $bulletin['local_status'],
                ['patched', 'verified_in_code'],
                true
            )
        );

        return match ($status) {
            'secure' => sprintf('%s has no open security bulletins.', $version),
            'patched_verified' => sprintf(
                '%s is affected by %d bulletin(s); every isolated patch is verified in the code.',
                $version,
                count($bulletins)
            ),
            'patched_per_local_records' => sprintf(
                '%s is affected by %d bulletin(s); local records show every isolated patch applied.',
                $version,
                count($bulletins)
            ),
            'eol' => sprintf('%s is end of life and receives no further security fixes.', $version),
            default => sprintf(
                '%s is affected by %d bulletin(s); %d not verified or recorded as patched: %s.',
                $version,
                count($bulletins),
                count($open),
                implode(', ', array_column($open, 'id'))
            ),
        };
    }

    /**
     * @param array<string, mixed> $advisories
     * @return string
     */
    private function packageSummary(array $advisories): string
    {
        if (!$advisories['available']) {
            return '';
        }

        return $advisories['vulnerable'] === []
            ? sprintf(' No known advisories against %d locked package(s).', $advisories['checked'])
            : sprintf(
                ' %d of %d locked package(s) have known advisories.',
                count($advisories['vulnerable']),
                $advisories['checked']
            );
    }

    /**
     * The release in $candidates on the installed one's line: 2.4.8-p5 for
     * 2.4.8-p2. Null when the list carries none.
     *
     * @param string $version
     * @param string[] $candidates
     * @return string|null
     */
    private function sameLine(string $version, array $candidates): ?string
    {
        $base = explode('-', $version, 2)[0];
        foreach ($candidates as $candidate) {
            if (explode('-', $candidate, 2)[0] === $base) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The weaker of two evidence statuses.
     *
     * A bulletin is only as patched as its least-evidenced isolated patch:
     * Adobe's isolated patches are not cumulative, so where a bulletin lists
     * more than one for a release, one applied and one not is still exposed.
     *
     * @param string|null $current
     * @param string $candidate
     * @return string
     */
    private function weaker(?string $current, string $candidate): string
    {
        $rank = array_flip(array_keys(self::BULLETIN_STATUS));

        return $current === null || $rank[$candidate] > $rank[$current] ? $candidate : $current;
    }
}
