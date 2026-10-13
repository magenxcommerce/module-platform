<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

use Magento\Framework\Exception\LocalizedException;

/**
 * Whether this store could move to the next Magento release, and what is in
 * the way.
 *
 * The candidates are the releases an upgrade is usually weighed between: the
 * one magento.watch recommends for this release's security, the newest patch
 * on the same line and the newest release overall — or exactly one, when the
 * caller names it. Each is checked three ways:
 *
 * - **Installable.** The release's product metapackage constrains PHP; a PHP
 *   it excludes means composer will not install the release at all. That is a
 *   blocker, and the only PHP finding that is.
 * - **Tested.** Adobe lists the versions of PHP, the database, the search
 *   engine, Redis and RabbitMQ a release was tested with. Those are lists, not
 *   ranges, so anything outside them is a warning to look at, not a refusal.
 * - **Extensions.** Every third-party package in composer.lock whose own
 *   requirements exclude what the release pins. Composer would refuse the
 *   upgrade until each is updated, so each is a blocker.
 *
 * The installed release is checked the same way, because a store already
 * outside its own release's tested versions is worth knowing about before
 * anything moves. Direct dependencies with a newer stable version are listed
 * beside the candidates, when asked for — that costs one metadata request per
 * package on a cold cache.
 */
class CompatibilityReport
{
    /** Components that are one role filled by alternative products. */
    private const FAMILIES = [
        'database' => ['mariadb', 'mysql'],
        'search' => ['opensearch', 'elasticsearch'],
        'cache' => ['redis', 'valkey'],
        'queue' => ['rabbitmq', 'activemq'],
    ];

    /** Direct requirements checked for newer versions, at most. */
    private const MAX_OUTDATED_CHECKS = 40;

    /** Root requirements that are Magento itself rather than something to update separately. */
    private const NOT_UPDATED_SEPARATELY = '#^magento/(product-|project-|magento-cloud-metapackage$)#';

    private InstalledRelease $installedRelease;

    private MagentoWatch $magentoWatch;

    private EnvironmentVersions $environment;

    private VersionRequirements $requirements;

    private PackageVersions $packageVersions;

    private ComposerProject $project;

    private ExtensionCompatibility $extensions;

    private Constraints $constraints;

    /**
     * @param InstalledRelease $installedRelease
     * @param MagentoWatch $magentoWatch
     * @param EnvironmentVersions $environment
     * @param VersionRequirements $requirements
     * @param PackageVersions $packageVersions
     * @param ComposerProject $project
     * @param ExtensionCompatibility $extensions
     * @param Constraints $constraints
     */
    public function __construct(
        InstalledRelease $installedRelease,
        MagentoWatch $magentoWatch,
        EnvironmentVersions $environment,
        VersionRequirements $requirements,
        PackageVersions $packageVersions,
        ComposerProject $project,
        ExtensionCompatibility $extensions,
        Constraints $constraints
    ) {
        $this->installedRelease = $installedRelease;
        $this->magentoWatch = $magentoWatch;
        $this->environment = $environment;
        $this->requirements = $requirements;
        $this->packageVersions = $packageVersions;
        $this->project = $project;
        $this->extensions = $extensions;
        $this->constraints = $constraints;
    }

    /**
     * @param string|null $target Check only this release.
     * @param string|null $distribution Overrides the one detected from the edition.
     * @param bool $includeOutdated Also list direct dependencies with a newer stable version.
     * @return array<string, mixed>
     * @throws LocalizedException When Magento cannot name its release, or a named target has no data.
     */
    public function build(?string $target = null, ?string $distribution = null, bool $includeOutdated = false): array
    {
        $release = $this->installedRelease->get();
        $distribution = $distribution ?? $release['distribution'];
        $environment = $this->environment->get();
        $locked = $this->project->lockedPackages();

        $report = [
            'installed' => ['distribution' => $distribution] + $release,
            'environment' => $environment,
            'advisories' => ['available' => false, 'source' => 'magento.watch'],
            'candidates' => [],
        ];
        if ($includeOutdated) {
            $report['outdated'] = $this->outdated($locked['packages']);
        }

        $current = $this->magentoWatch->getVersion($distribution, $release['version']);
        if ($current === null) {
            $report['advisories']['reason'] = $this->magentoWatch->getLastError();
            $report['summary'] = 'Release requirements unavailable: ' . $this->magentoWatch->getLastError();

            return $report;
        }

        $report['advisories'] = [
            'available' => true,
            'source' => 'magento.watch',
            'fetched_at' => $current['fetched_at'],
            'cached' => $current['cached'],
        ];
        $report['current'] = $this->evaluate(
            $release['version'],
            $current['data'],
            ['installed'],
            $environment,
            $distribution,
            null
        );

        $supported = [];
        if ($target === null) {
            $supported = $this->magentoWatch->getSupportedVersions($distribution)['data'] ?? [];
        }
        $candidates = $target !== null
            ? [$target => ['requested']]
            : $this->candidates($release['version'], $current['data'], $supported);

        foreach ($candidates as $version => $reasons) {
            $data = is_array($supported[$version] ?? null) ? $supported[$version] : null;
            if ($data === null) {
                $lookup = $this->magentoWatch->getVersion($distribution, (string) $version);
                if ($lookup === null) {
                    if ($target !== null) {
                        throw new LocalizedException(__(
                            'No release data for %1: %2',
                            $target,
                            $this->magentoWatch->getLastError()
                        ));
                    }
                    continue;
                }
                $data = $lookup['data'];
            }
            $report['candidates'][] = $this->evaluate(
                (string) $version,
                $data,
                $reasons,
                $environment,
                $distribution,
                $locked
            );
        }

        $report['summary'] = $this->summarize($release['version'], $report['candidates']);

        return $report;
    }

    /**
     * The releases worth weighing, as version => why each was picked.
     *
     * @param string $installed
     * @param array<string, mixed> $current
     * @param array<string, mixed> $supported
     * @return array<string, string[]>
     */
    private function candidates(string $installed, array $current, array $supported): array
    {
        $candidates = [];

        $recommended = $current['security']['recommendedVersion'] ?? null;
        if (is_string($recommended) && $recommended !== '' && $recommended !== $installed) {
            $candidates[$recommended][] = 'recommended_for_security';
        }

        $installedDate = (string) ($current['releaseDate'] ?? '');
        $line = $this->line($installed);
        $newestOnLine = null;

        foreach ($supported as $version => $data) {
            $version = (string) $version;
            if (!is_array($data) || $version === $installed) {
                continue;
            }
            if (($data['isLatestVersion'] ?? false) === true) {
                $candidates[$version][] = 'latest_release';
            }
            $date = (string) ($data['releaseDate'] ?? '');
            if ($line !== null && $this->line($version) === $line && $date > $installedDate
                && ($newestOnLine === null || $date > $newestOnLine[1])
            ) {
                $newestOnLine = [$version, $date];
            }
        }

        if ($newestOnLine !== null) {
            $candidates[$newestOnLine[0]][] = 'latest_patch_on_line';
        }

        return $candidates;
    }

    /**
     * One release against what this store runs and has installed.
     *
     * @param string $version
     * @param array<string, mixed> $data
     * @param string[] $reasons
     * @param array<string, array<int, array<string, string>>> $environment {@see EnvironmentVersions::get()}
     * @param string $distribution
     * @param array<string, mixed>|null $locked {@see ComposerProject::lockedPackages()}; null skips extensions.
     * @return array<string, mixed>
     */
    private function evaluate(
        string $version,
        array $data,
        array $reasons,
        array $environment,
        string $distribution,
        ?array $locked
    ): array {
        $required = is_array($data['systemRequirements'] ?? null) ? $data['systemRequirements'] : [];
        $manifest = $distribution === 'mage-os' ? null : $this->packageVersions->releaseManifest($version);
        $manifestError = $manifest === null
            ? ($distribution === 'mage-os'
                ? 'Mage-OS release manifests are not checked yet'
                : $this->packageVersions->getLastError())
            : null;

        $components = [];
        $covered = [];
        foreach ($environment['measured'] as $measured) {
            $components[] = $this->compareComponent($measured, $required, $manifest['require']['php'] ?? null);
            $covered = array_merge($covered, self::FAMILIES[$measured['component']] ?? [$measured['product']]);
        }

        $notChecked = array_values(array_filter(
            array_keys($required),
            static fn ($key): bool => !in_array($key, $covered, true) && !str_starts_with((string) $key, 'aws-')
        ));

        $extensions = null;
        if ($locked !== null) {
            $extensions = $this->extensionsFor($locked, $manifest, $manifestError);
        }

        $blockers = $this->componentsWith($components, 'blocker');
        foreach ($extensions['conflicts'] ?? [] as $conflict) {
            if (!in_array($conflict['package'], $blockers, true)) {
                $blockers[] = $conflict['package'];
            }
        }

        $evaluation = [
            'version' => $version,
            'reasons' => $reasons,
            'release_date' => $data['releaseDate'] ?? null,
            'eol_date' => $data['eolDate'] ?? null,
            'status_label' => $data['statusLabel'] ?? null,
            'security' => $this->securityOf($data),
            'compatible' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $this->componentsWith($components, 'warning'),
            'components' => $components,
            'not_checked' => $notChecked,
            'manifest' => $manifest === null
                ? ['available' => false, 'reason' => $manifestError]
                : [
                    'available' => true,
                    'package' => $manifest['package'],
                    'php' => $manifest['require']['php'] ?? null,
                ],
        ];
        if ($extensions !== null) {
            $evaluation['extensions'] = $extensions;
        }

        return $evaluation;
    }

    /**
     * @param array<string, mixed> $locked
     * @param array{package: string, require: array<string, string>}|null $manifest
     * @param string|null $manifestError
     * @return array<string, mixed>
     */
    private function extensionsFor(array $locked, ?array $manifest, ?string $manifestError): array
    {
        if ($manifest === null) {
            return ['available' => false, 'reason' => $manifestError, 'checked' => 0, 'conflicts' => []];
        }
        if ($locked['packages'] === []) {
            return [
                'available' => false,
                'reason' => sprintf('%s is %s', $locked['path'], $locked['state']),
                'checked' => 0,
                'conflicts' => [],
            ];
        }

        return ['available' => true] + $this->extensions->check($locked['packages'], $manifest['require']);
    }

    /**
     * @param array<string, string> $measured
     * @param array<string, mixed> $required
     * @param string|null $phpConstraint The release metapackage's PHP constraint, when known.
     * @return array<string, mixed>
     */
    private function compareComponent(array $measured, array $required, ?string $phpConstraint): array
    {
        $product = $measured['product'];
        $row = [
            'component' => $measured['component'],
            'product' => $product,
            'installed' => $measured['version'],
        ];

        if ($measured['component'] === 'php') {
            return $row + $this->comparePhp($measured['version'], (array) ($required['php'] ?? []), $phpConstraint);
        }

        if (is_array($required[$product] ?? null)) {
            $listed = array_values($required[$product]);
            $verdict = $this->requirements->compare($measured['version'], $listed);

            return $row + [
                'required' => $listed,
                'verdict' => $verdict,
                'severity' => $this->severity($verdict),
            ];
        }

        $alternatives = [];
        foreach (self::FAMILIES[$measured['component']] ?? [] as $other) {
            if ($other !== $product && is_array($required[$other] ?? null)) {
                $alternatives[$other] = array_values($required[$other]);
            }
        }

        if ($alternatives === []) {
            return $row + ['required' => [], 'verdict' => 'not_declared', 'severity' => 'info'];
        }

        return $row + [
            'required' => $alternatives,
            'verdict' => 'product_not_listed',
            'severity' => 'warning',
            'note' => sprintf(
                'The release lists %s, not %s; their version numbers are not comparable.',
                implode(' or ', array_keys($alternatives)),
                $product
            ),
        ];
    }

    /**
     * PHP is judged by the metapackage's composer constraint first: outside it,
     * the release cannot be installed. Inside it but outside the tested list,
     * it installs and is a warning.
     *
     * @param string $installed
     * @param string[] $tested
     * @param string|null $constraint
     * @return array<string, mixed>
     */
    private function comparePhp(string $installed, array $tested, ?string $constraint): array
    {
        $verdict = $this->requirements->compare($installed, array_values($tested));
        $installable = $constraint === null
            ? null
            : $this->constraints->satisfies((string) $this->requirements->normalize($installed), $constraint);

        if ($installable === false) {
            $severity = 'blocker';
            $verdict = 'not_installable';
        } else {
            $severity = $this->severity($verdict);
        }

        return [
            'required' => array_values($tested),
            'composer_constraint' => $constraint,
            'installable' => $installable,
            'verdict' => $verdict,
            'severity' => $severity,
        ];
    }

    /**
     * @param string $verdict
     * @return string
     */
    private function severity(string $verdict): string
    {
        return match ($verdict) {
            VersionRequirements::SUPPORTED => 'ok',
            VersionRequirements::NOT_COMPARABLE => 'info',
            default => 'warning',
        };
    }

    /**
     * Direct requirements that have a newer stable release than the one locked.
     *
     * @param array<string, array<string, mixed>> $packages
     * @return array<string, mixed>
     */
    private function outdated(array $packages): array
    {
        $root = $this->project->rootRequirements();
        if ($root['require'] === [] || $packages === []) {
            return [
                'available' => false,
                'reason' => $root['require'] === []
                    ? sprintf('%s is %s', $root['path'], $root['state'])
                    : 'composer.lock could not be read',
                'checked' => 0,
                'outdated' => [],
                'unknown' => [],
            ];
        }

        $checked = 0;
        $outdated = [];
        $unknown = [];
        foreach (array_keys($root['require']) as $name) {
            if (preg_match(self::NOT_UPDATED_SEPARATELY, $name) === 1 || !isset($packages[$name])) {
                continue;
            }
            if ($checked >= self::MAX_OUTDATED_CHECKS) {
                $unknown[] = ['package' => $name, 'reason' => 'over the per-load limit of direct requirements checked'];
                continue;
            }
            $checked++;

            $locked = (string) $packages[$name]['version'];
            $latest = $this->packageVersions->newestStable($name);
            if ($latest === null) {
                $unknown[] = ['package' => $name, 'reason' => $this->packageVersions->getLastError()];
                continue;
            }
            if ($this->constraints->greaterThan($latest, $locked) === true) {
                $outdated[] = [
                    'package' => $packages[$name]['name'],
                    'locked' => $locked,
                    'latest' => $latest,
                    'major' => $this->constraints->isMajorApart($latest, $locked),
                ];
            }
        }

        return [
            'available' => $checked > 0,
            'checked' => $checked,
            'outdated' => $outdated,
            'unknown' => $unknown,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $components
     * @param string $severity
     * @return string[]
     */
    private function componentsWith(array $components, string $severity): array
    {
        $names = [];
        foreach ($components as $component) {
            if ($component['severity'] === $severity) {
                $names[] = $component['component'];
            }
        }

        return $names;
    }

    /**
     * The release's own security standing, from whichever shape the API gave:
     * list endpoints carry a `securitySummary`, single-release ones a full
     * `security` block.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function securityOf(array $data): array
    {
        if (is_array($data['securitySummary'] ?? null)) {
            return [
                'status' => $data['securitySummary']['status'] ?? null,
                'isolated_patches_needed' => $data['securitySummary']['requiredPatches'] ?? null,
                'upgrade_required' => $data['securitySummary']['upgradeRequired'] ?? null,
            ];
        }

        $patches = 0;
        $needsUpgrade = false;
        foreach ((array) ($data['security']['vulnerableTo'] ?? []) as $bulletin) {
            $count = count((array) ($bulletin['isolatedPatches'] ?? []));
            $patches += $count;
            $needsUpgrade = $needsUpgrade || ($count === 0 && ($bulletin['fixedIn'] ?? null) !== null);
        }

        return [
            'status' => $data['security']['status'] ?? null,
            'isolated_patches_needed' => $patches,
            'upgrade_required' => $needsUpgrade,
        ];
    }

    /**
     * "2.4.8-p5" belongs to the 2.4.8 line.
     *
     * @param string $version
     * @return string|null
     */
    private function line(string $version): ?string
    {
        return preg_match('/^(\d+\.\d+\.\d+)/', $version, $match) === 1 ? $match[1] : null;
    }

    /**
     * @param string $installed
     * @param array<int, array<string, mixed>> $candidates
     * @return string
     */
    private function summarize(string $installed, array $candidates): string
    {
        if ($candidates === []) {
            return sprintf('No newer supported release than %s was found.', $installed);
        }

        $parts = [];
        foreach ($candidates as $candidate) {
            $parts[] = $candidate['compatible']
                ? sprintf('%s: no blockers, %d warning(s)', $candidate['version'], count($candidate['warnings']))
                : sprintf('%s: blocked by %s', $candidate['version'], implode(', ', $candidate['blockers']));
        }

        return sprintf('From %s — %s.', $installed, implode('; ', $parts));
    }
}
