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
use Magenx\Platform\Model\Security\PatchEvidence;
use Magenx\Platform\Model\Security\SecurityReport;

/**
 * The Security tab: Adobe bulletins open against this release, what this store
 * records about the isolated patches that close them, and known advisories
 * against the locked composer packages.
 *
 * Every finding comes from {@see SecurityReport}; this class only lays it out.
 * The MCP server's `security_status` tool returns the same report unrendered.
 */
class Security implements CollectorInterface
{
    /** A store with hundreds of vulnerable packages has a different problem than a long tab. */
    private const MAX_PACKAGE_ROWS = 50;

    private const EVIDENCE = [
        PatchEvidence::APPLIED => ['Applied', Status::OK],
        PatchEvidence::DECLARED => ['Declared, not verified', Status::WARN],
        PatchEvidence::PRESENT => ['Hotfix file present', Status::WARN],
        PatchEvidence::NO_EVIDENCE => ['No record found', Status::ERROR],
    ];

    private SecurityReport $report;

    private ResultFactory $resultFactory;

    /**
     * @param SecurityReport $report
     * @param ResultFactory $resultFactory
     */
    public function __construct(SecurityReport $report, ResultFactory $resultFactory)
    {
        $this->report = $report;
        $this->resultFactory = $resultFactory;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Security';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        $report = $this->report->build();

        /** @var Result $result */
        $result = $this->resultFactory->create();
        $result->setSummary((string) $report['summary']);

        $this->addRelease($result, $report);
        foreach ($report['open_bulletins'] as $bulletin) {
            $this->addBulletin($result, $bulletin);
        }
        if ($report['apply_order'] !== []) {
            $result->add(
                'Apply Order',
                'Isolated Patches',
                implode(' → ', $report['apply_order']),
                Status::ERROR,
                'Not cumulative: each is needed, applied oldest bulletin first. Check each bulletin for '
                . 'follow-up steps, such as rotating credentials.'
            );
        }
        $this->addPatchRecords($result, $report['patch_records']['sources']);
        $this->addPackages($result, $report['package_advisories']);

        return $result;
    }

    /**
     * @param Result $result
     * @param array<string, mixed> $report
     * @return void
     */
    private function addRelease(Result $result, array $report): void
    {
        $section = 'Release';
        $installed = $report['installed'];
        $result->add($section, 'Version', (string) $installed['version']);
        $result->add($section, 'Edition', sprintf('%s (%s)', $installed['edition'], $installed['distribution']));

        if (!$report['advisories']['available']) {
            $result->add(
                $section,
                'Bulletins',
                (string) ($report['advisories']['reason'] ?? 'unavailable'),
                Status::UNAVAILABLE,
                'Bulletins come from magento.watch. Local patch records below are read either way.'
            );

            return;
        }

        $lifecycle = $report['lifecycle'];
        $result->add(
            $section,
            'Support',
            sprintf('%s, end of life %s', $lifecycle['status_label'] ?? 'unknown', $lifecycle['eol_date'] ?? 'n/a'),
            $lifecycle['is_eol'] ? Status::ERROR : Status::OK,
            'An end-of-life release gets no further security fixes, isolated or otherwise.'
        );
        $result->add(
            $section,
            'Open Bulletins',
            (string) count($report['open_bulletins']),
            $report['open_bulletins'] === [] ? Status::OK : $this->statusFor((string) $report['status']),
            'Adobe security bulletins this release is affected by, per magento.watch.'
        );
        if (is_string($report['recommended_version'] ?? null)
            && $report['recommended_version'] !== $installed['version']
        ) {
            $result->add($section, 'Recommended Release', $report['recommended_version'], Status::WARN);
        }
        $result->add(
            $section,
            'Source',
            sprintf(
                'magento.watch, %s%s',
                $report['advisories']['fetched_at'],
                $report['advisories']['cached'] ? ' (cached)' : ''
            )
        );
    }

    /**
     * @param Result $result
     * @param array<string, mixed> $bulletin
     * @return void
     */
    private function addBulletin(Result $result, array $bulletin): void
    {
        $section = sprintf(
            '%s · %s · %s',
            $bulletin['id'],
            $bulletin['severity'] ?? 'unrated',
            $bulletin['published_date'] ?? 'undated'
        );
        $cves = implode(', ', $bulletin['cves']);

        if ($bulletin['isolated_patches'] === []) {
            $result->add(
                $section,
                'Fix',
                $bulletin['fixed_in'] !== null ? 'Upgrade to ' . $bulletin['fixed_in'] : 'No fix published yet',
                Status::ERROR,
                $cves
            );

            return;
        }

        foreach ($bulletin['isolated_patches'] as $patch) {
            [$label, $status] = self::EVIDENCE[$patch['evidence']] ?? self::EVIDENCE[PatchEvidence::NO_EVIDENCE];
            $sources = array_unique(array_column($patch['matched_records'], 'source'));
            $result->add(
                $section,
                (string) $patch['id'],
                $sources === [] ? $label : sprintf('%s (%s)', $label, implode(', ', $sources)),
                $status,
                trim($cves . ' ' . ($patch['url'] ?? ''))
            );
        }
    }

    /**
     * @param Result $result
     * @param array<int, array<string, mixed>> $sources
     * @return void
     */
    private function addPatchRecords(Result $result, array $sources): void
    {
        foreach ($sources as $source) {
            $unreadable = $source['state'] === 'unreadable';
            $result->add(
                'Patch Records',
                (string) $source['path'],
                $source['state'] === 'read' ? sprintf('%d record(s)', $source['entries']) : (string) $source['state'],
                $unreadable ? Status::WARN : Status::INFO,
                $unreadable
                    ? 'The PHP user cannot read this, so patches recorded here are invisible to this tab.'
                    : ''
            );
        }
    }

    /**
     * @param Result $result
     * @param array<string, mixed> $advisories
     * @return void
     */
    private function addPackages(Result $result, array $advisories): void
    {
        $section = 'Composer Packages';
        if (!$advisories['available']) {
            $reason = (string) ($advisories['reason'] ?? 'unavailable');
            $result->add($section, 'Advisories', $reason, Status::UNAVAILABLE);

            return;
        }

        if ($advisories['vulnerable'] === []) {
            $result->add(
                $section,
                'Advisories',
                sprintf('None known for %d locked package(s)', $advisories['checked']),
                Status::OK,
                'Packagist\'s advisory database — what composer audit reads — against composer.lock.'
            );
        }

        foreach (array_slice($advisories['vulnerable'], 0, self::MAX_PACKAGE_ROWS) as $package) {
            $ids = [];
            $worst = Status::WARN;
            foreach ($package['advisories'] as $advisory) {
                $ids[] = $advisory['cve'] ?? $advisory['id'];
                if (in_array($advisory['severity'], ['high', 'critical'], true)) {
                    $worst = Status::ERROR;
                }
            }
            $result->add(
                $section,
                sprintf('%s %s', $package['package'], $package['version']),
                implode(', ', array_unique($ids)),
                $worst,
                implode(' · ', array_unique(array_column($package['advisories'], 'title')))
            );
        }

        if (isset($advisories['reason'])) {
            $result->add($section, 'Incomplete', (string) $advisories['reason'], Status::WARN);
        }
    }

    /**
     * @param string $status
     * @return string
     */
    private function statusFor(string $status): string
    {
        return match ($status) {
            'secure', 'patched_per_local_records' => Status::OK,
            'possibly_patched' => Status::WARN,
            default => Status::ERROR,
        };
    }
}
