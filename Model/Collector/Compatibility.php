<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Collector;

use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\ResultFactory;
use Magenx\Platform\Model\Metric\Status;
use Magenx\Platform\Model\Security\CompatibilityReport;

/**
 * The Compatibility tab: this store's services and extensions against the
 * releases it could upgrade to, and the direct dependencies that have moved on.
 *
 * Every finding comes from {@see CompatibilityReport}; this class only lays it
 * out. The MCP server's `upgrade_compatibility` tool returns the same report
 * unrendered.
 */
class Compatibility implements CollectorInterface
{
    private const SEVERITY = [
        'ok' => Status::OK,
        'info' => Status::INFO,
        'warning' => Status::WARN,
        'blocker' => Status::ERROR,
    ];

    private const COMPONENT_LABELS = [
        'php' => 'PHP',
        'database' => 'Database',
        'search' => 'Search',
        'cache' => 'Cache',
        'queue' => 'Queue',
    ];

    private const REASONS = [
        'installed' => 'installed',
        'recommended_for_security' => 'recommended',
        'latest_patch_on_line' => 'newest patch on this line',
        'latest_release' => 'newest release',
        'requested' => 'requested',
    ];

    private CompatibilityReport $report;

    private ResultFactory $resultFactory;

    /**
     * @param CompatibilityReport $report
     * @param ResultFactory $resultFactory
     */
    public function __construct(CompatibilityReport $report, ResultFactory $resultFactory)
    {
        $this->report = $report;
        $this->resultFactory = $resultFactory;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Compatibility';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        $report = $this->report->build(null, null, true);

        /** @var Result $result */
        $result = $this->resultFactory->create();
        $result->setSummary((string) $report['summary']);

        if (!$report['advisories']['available']) {
            $result->add(
                'Release Requirements',
                'magento.watch',
                (string) ($report['advisories']['reason'] ?? 'unavailable'),
                Status::UNAVAILABLE,
                'Measured versions are listed below; there is nothing to compare them with.'
            );
            foreach ($report['environment']['measured'] as $measured) {
                $result->add(
                    'Measured',
                    self::COMPONENT_LABELS[$measured['component']] ?? $measured['component'],
                    (string) $measured['version']
                );
            }
        } else {
            $this->addRelease($result, $report['current']);
            foreach ($report['candidates'] as $candidate) {
                $this->addRelease($result, $candidate);
            }
            if ($report['candidates'] === []) {
                $result->add('Upgrades', 'Newer Releases', 'None supported', Status::OK);
            }
        }

        foreach ($report['environment']['unmeasured'] as $unmeasured) {
            $result->add('Not Measured', ucfirst((string) $unmeasured['component']), (string) $unmeasured['reason']);
        }
        if (isset($report['outdated'])) {
            $this->addOutdated($result, $report['outdated']);
        }

        return $result;
    }

    /**
     * @param Result $result
     * @param array<string, mixed> $release
     * @return void
     */
    private function addRelease(Result $result, array $release): void
    {
        $reasons = array_map(
            static fn (string $reason): string => self::REASONS[$reason] ?? $reason,
            $release['reasons']
        );
        $section = sprintf('%s (%s)', $release['version'], implode(', ', $reasons));

        foreach ($release['components'] as $component) {
            $result->add(
                $section,
                self::COMPONENT_LABELS[$component['component']] ?? (string) $component['component'],
                $this->describe($component),
                self::SEVERITY[$component['severity']] ?? Status::INFO,
                $this->hint($component)
            );
        }

        if ($release['not_checked'] !== []) {
            $result->add(
                $section,
                'Not Checked',
                implode(', ', $release['not_checked']),
                Status::INFO,
                'Listed by the release but not measurable from PHP.'
            );
        }

        if (!isset($release['extensions'])) {
            return;
        }
        $extensions = $release['extensions'];
        if (!$extensions['available']) {
            $result->add($section, 'Extensions', (string) $extensions['reason'], Status::UNAVAILABLE);

            return;
        }
        if ($extensions['conflicts'] === []) {
            $result->add(
                $section,
                'Extensions',
                sprintf('%d third-party package(s) fit', $extensions['checked']),
                Status::OK,
                'Each package\'s requirements, checked against what this release pins.'
            );

            return;
        }
        foreach ($extensions['conflicts'] as $conflict) {
            $result->add(
                $section,
                sprintf('%s %s', $conflict['package'], $conflict['version']),
                sprintf(
                    'requires %s %s; %s needs %s',
                    $conflict['requires'],
                    $conflict['constraint'],
                    $release['version'],
                    $conflict['release_requires']
                ),
                Status::ERROR,
                'Composer will refuse this upgrade until the extension is updated or removed.'
            );
        }
    }

    /**
     * @param array<string, mixed> $component
     * @return string
     */
    private function describe(array $component): string
    {
        $required = $component['required'];
        if ($required === []) {
            return sprintf('%s (not declared by this release)', $component['installed']);
        }
        if (!array_is_list($required)) {
            $parts = [];
            foreach ($required as $product => $versions) {
                $parts[] = $product . ' ' . implode(', ', $versions);
            }

            return sprintf('%s; release lists %s', $component['installed'], implode(' or ', $parts));
        }

        return sprintf('%s; tested %s', $component['installed'], implode(', ', $required));
    }

    /**
     * @param array<string, mixed> $component
     * @return string
     */
    private function hint(array $component): string
    {
        if (isset($component['note'])) {
            return (string) $component['note'];
        }
        if ($component['component'] === 'php' && ($component['composer_constraint'] ?? null) !== null) {
            return $component['installable'] === false
                ? sprintf(
                    'The release requires PHP %s; composer will not install it.',
                    $component['composer_constraint']
                )
                : sprintf('Installable: the release allows PHP %s.', $component['composer_constraint']);
        }

        return match ($component['verdict']) {
            'older_than_tested' => 'Older than every version this release was tested with.',
            'newer_than_tested' => 'Newer than every version this release was tested with.',
            'not_listed' => 'Between the versions this release was tested with.',
            default => '',
        };
    }

    /**
     * @param Result $result
     * @param array<string, mixed> $outdated
     * @return void
     */
    private function addOutdated(Result $result, array $outdated): void
    {
        $section = 'Direct Dependencies';
        if (!$outdated['available']) {
            $reason = (string) ($outdated['reason'] ?? 'unavailable');
            $result->add($section, 'Newer Versions', $reason, Status::UNAVAILABLE);

            return;
        }
        if ($outdated['outdated'] === []) {
            $result->add(
                $section,
                'Newer Versions',
                sprintf('All %d checked are current', $outdated['checked']),
                Status::OK
            );
        }
        foreach ($outdated['outdated'] as $package) {
            $result->add(
                $section,
                (string) $package['package'],
                sprintf('%s → %s', $package['locked'], $package['latest']),
                $package['major'] ? Status::WARN : Status::INFO,
                $package['major'] ? 'A new major version: expect breaking changes.' : ''
            );
        }
        if ($outdated['unknown'] !== []) {
            $result->add(
                $section,
                'Not Found Publicly',
                (string) count($outdated['unknown']),
                Status::INFO,
                implode(', ', array_column($outdated['unknown'], 'package'))
                . ' — from a private repository, or not published.'
            );
        }
    }
}
