<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework\Security;

use Magenx\Platform\Model\Collector\Compatibility;
use Magenx\Platform\Model\Collector\Security;
use Magenx\Platform\Model\Metric\ResultFactory;
use Magenx\Platform\Model\Metric\Status;
use Magenx\Platform\Model\Security\CompatibilityReport;
use Magenx\Platform\Model\Security\SecurityReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two tabs only lay their report out; what is pinned is that the colour
 * of each row says what the report found — red for an unpatched bulletin or a
 * blocker, amber for a declared patch or an untested version.
 */
#[CoversClass(Security::class)]
#[CoversClass(Compatibility::class)]
class TabsTest extends TestCase
{
    public function testTheSecurityTabColoursEachPatchByItsEvidence(): void
    {
        $report = $this->createMock(SecurityReport::class);
        $report->method('build')->willReturn([
            'installed' => ['version' => '2.4.8-p5', 'edition' => 'Community', 'distribution' => 'magento-community'],
            'advisories' => ['available' => true, 'fetched_at' => 'now', 'cached' => true],
            'lifecycle' => ['status_label' => 'supported', 'eol_date' => '2028-04-11', 'is_eol' => false],
            'status' => 'vulnerable',
            'recommended_version' => '2.4.8-p5',
            'open_bulletins' => [
                $this->bulletin('APSB26-146', 'VULN-39341', 'declared', [['source' => 'composer_json']]),
                $this->bulletin('APSB26-73', '2026-jul', 'no_evidence', []),
            ],
            'apply_order' => ['2026-jul', 'VULN-39341'],
            'patch_records' => ['sources' => [
                ['source' => 'composer_json', 'path' => 'composer.json', 'state' => 'read', 'entries' => 1],
                ['source' => 'composer_patches_v2', 'path' => 'patches.lock.json', 'state' => 'unreadable', 'entries' => 0],
            ]],
            'package_advisories' => ['available' => true, 'checked' => 2, 'vulnerable' => [[
                'package' => 'guzzlehttp/guzzle',
                'version' => '7.9.2',
                'advisories' => [['id' => 'GHSA-x', 'cve' => 'CVE-2026-69246', 'severity' => 'high', 'title' => 'Host bypass']],
            ]]],
            'summary' => '2.4.8-p5 is affected by 2 bulletin(s).',
        ]);

        $tab = (new Security($report, new ResultFactory()))->collect()->toArray();
        $rows = $this->rows($tab);

        $this->assertSame(Status::ERROR, $tab['status']);
        $this->assertSame([Status::WARN, 'Declared, not verified (composer_json)'], $rows['VULN-39341']);
        $this->assertSame([Status::ERROR, 'No record found'], $rows['2026-jul']);
        $this->assertSame([Status::WARN, 'unreadable'], $rows['patches.lock.json']);
        $this->assertSame([Status::ERROR, 'CVE-2026-69246'], $rows['guzzlehttp/guzzle 7.9.2']);
        $this->assertSame('2026-jul → VULN-39341', $rows['Isolated Patches'][1]);
    }

    public function testTheCompatibilityTabShowsBlockersAndOutdatedPackages(): void
    {
        $component = static fn (string $name, string $installed, array $required, string $verdict, string $severity): array => [
            'component' => $name,
            'product' => $name,
            'installed' => $installed,
            'required' => $required,
            'verdict' => $verdict,
            'severity' => $severity,
        ];
        $report = $this->createMock(CompatibilityReport::class);
        $report->method('build')->willReturn([
            'environment' => ['measured' => [], 'unmeasured' => [['component' => 'queue', 'reason' => 'tab off']]],
            'advisories' => ['available' => true],
            'current' => [
                'version' => '2.4.8-p5',
                'reasons' => ['installed'],
                'components' => [$component('search', 'Opensearch 2.19.5', ['3'], 'older_than_tested', 'warning')],
                'not_checked' => ['composer'],
            ],
            'candidates' => [[
                'version' => '2.4.9',
                'reasons' => ['latest_release'],
                'components' => [
                    ['composer_constraint' => '~8.5.0', 'installable' => false]
                        + $component('php', '8.4.22', ['8.5'], 'not_installable', 'blocker'),
                ],
                'not_checked' => [],
                'extensions' => ['available' => true, 'checked' => 3, 'conflicts' => [[
                    'package' => 'acme/module-old',
                    'version' => '1.2.0',
                    'requires' => 'magento/framework',
                    'constraint' => '102.0.*',
                    'release_requires' => '103.0.9',
                ]]],
            ]],
            'outdated' => [
                'available' => true,
                'checked' => 2,
                'outdated' => [['package' => 'stripe/stripe-php', 'locked' => 'v17.1.0', 'latest' => 'v22.0.0', 'major' => true]],
                'unknown' => [['package' => 'acme/private', 'reason' => 'HTTP 404']],
            ],
            'summary' => 'From 2.4.8-p5 — 2.4.9: blocked by php, acme/module-old.',
        ]);

        $tab = (new Compatibility($report, new ResultFactory()))->collect()->toArray();
        $rows = $this->rows($tab);

        $this->assertSame(Status::ERROR, $tab['status']);
        $this->assertSame(
            ['2.4.8-p5 (installed)', '2.4.9 (newest release)', 'Not Measured', 'Direct Dependencies'],
            array_column($tab['sections'], 'label')
        );
        $this->assertSame(Status::ERROR, $rows['PHP'][0]);
        $this->assertSame(Status::ERROR, $rows['acme/module-old 1.2.0'][0]);
        $this->assertSame([Status::WARN, 'v17.1.0 → v22.0.0'], $rows['stripe/stripe-php']);
        $this->assertSame([Status::INFO, '1'], $rows['Not Found Publicly']);
    }

    private function bulletin(string $id, string $patch, string $evidence, array $matched): array
    {
        return [
            'id' => $id,
            'severity' => 'critical',
            'published_date' => '2026-09-07',
            'cves' => ['CVE-2026-75650'],
            'fixed_in' => null,
            'isolated_patches' => [[
                'id' => $patch,
                'url' => 'https://example.test',
                'evidence' => $evidence,
                'matched_records' => $matched,
            ]],
            'local_status' => $evidence,
        ];
    }

    /**
     * Every row in the tab as label => [status, value].
     */
    private function rows(array $tab): array
    {
        $rows = [];
        foreach ($tab['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $rows[$row['label']] = [$row['status'], $row['value']];
            }
        }

        return $rows;
    }
}
