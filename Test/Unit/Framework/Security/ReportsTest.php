<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework\Security;

use Magenx\Platform\Model\Security\CompatibilityReport;
use Magenx\Platform\Model\Security\ComposerProject;
use Magenx\Platform\Model\Security\Constraints;
use Magenx\Platform\Model\Security\EnvironmentVersions;
use Magenx\Platform\Model\Security\ExtensionCompatibility;
use Magenx\Platform\Model\Security\InstalledRelease;
use Magenx\Platform\Model\Security\MagentoWatch;
use Magenx\Platform\Model\Security\PackageAdvisories;
use Magenx\Platform\Model\Security\PackageVersions;
use Magenx\Platform\Model\Security\PatchEvidence;
use Magenx\Platform\Model\Security\SecurityReport;
use Magenx\Platform\Model\Security\VersionRequirements;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Both reports against the store they were written for: 2.4.8-p5 on PHP 8.4,
 * MariaDB 11.4, OpenSearch 2.19, Redis 8.6 and RabbitMQ 4.1, with the four
 * bulletins open against it in October 2026. Release data is magento.watch's
 * and the Mage-OS mirror's, cut to the keys read.
 */
#[CoversClass(SecurityReport::class)]
#[CoversClass(CompatibilityReport::class)]
class ReportsTest extends TestCase
{
    private InstalledRelease&MockObject $release;

    private MagentoWatch&MockObject $watch;

    private ComposerProject&MockObject $project;

    protected function setUp(): void
    {
        $this->release = $this->createMock(InstalledRelease::class);
        $this->release->method('get')->willReturn([
            'version' => '2.4.8-p5',
            'edition' => 'Community',
            'distribution' => 'magento-community',
            'magento_version' => '2.4.8-p5',
        ]);
        $this->watch = $this->createMock(MagentoWatch::class);
        $this->project = $this->createMock(ComposerProject::class);
        $this->project->method('lockedPackages')->willReturn(['packages' => [
            'acme/module-old' => [
                'name' => 'acme/module-old',
                'version' => '1.2.0',
                'require' => ['magento/framework' => '103.0.8-p5'],
            ],
        ], 'state' => 'read', 'path' => 'composer.lock']);
        $this->project->method('rootRequirements')->willReturn(['require' => [
            'magento/product-community-edition' => '2.4.8-p5',
            'acme/module-old' => '^1.2',
        ], 'state' => 'read', 'path' => 'composer.json']);
    }

    public function testWithoutRecordsEveryBulletinIsOpenAndTheOrderIsOldestFirst(): void
    {
        $this->watch->method('getVersion')->willReturn($this->wrap($this->p5()));

        $report = $this->security(PatchEvidence::NO_EVIDENCE)->build();

        $this->assertSame('vulnerable', $report['status']);
        $this->assertSame(['2026-jul', '2026-aug', 'VULN-39341', '2026-sep'], $report['apply_order']);
        $this->assertSame(['APSB26-49'], $report['fixed_by_installed_release']);
        $this->assertStringContainsString('No known advisories against 1 locked package', $report['summary']);
    }

    public function testRecordedPatchesCloseTheirBulletins(): void
    {
        $this->watch->method('getVersion')->willReturn($this->wrap($this->p5()));

        $report = $this->security(PatchEvidence::APPLIED)->build();

        $this->assertSame('patched_per_local_records', $report['status']);
        $this->assertSame([], $report['apply_order']);
    }

    public function testPatchesVerifiedInTheCodeCloseEveryBulletin(): void
    {
        $this->watch->method('getVersion')->willReturn($this->wrap($this->p5()));

        $report = $this->security(PatchEvidence::VERIFIED)->build();

        $this->assertSame('patched_verified', $report['status']);
        $this->assertSame([], $report['apply_order']);
        $this->assertStringContainsString('verified in the code', $report['summary']);
    }

    public function testCodeThatContradictsARecordKeepsTheBulletinOpen(): void
    {
        $this->watch->method('getVersion')->willReturn($this->wrap($this->p5()));

        $report = $this->security(PatchEvidence::NOT_IN_CODE)->build();

        $this->assertSame('vulnerable', $report['status']);
        $this->assertSame('not_in_code', $report['open_bulletins'][0]['local_status']);
        $this->assertCount(4, $report['apply_order']);
    }

    public function testAPatchNotPublishedForThisReleaseNeedsAnUpgradeFirst(): void
    {
        $data = $this->p5();
        $data['security']['vulnerableTo'][0]['isolatedPatches'][0]['appliesTo'] = ['2.4.9', '2.4.8-p6', '2.4.7-p10'];
        $this->watch->method('getVersion')->willReturn($this->wrap($data));

        $report = $this->security(PatchEvidence::NO_EVIDENCE)->build();
        $bulletin = $report['open_bulletins'][0];

        $this->assertSame('upgrade_required', $bulletin['local_status']);
        $this->assertSame('2.4.8-p6', $bulletin['upgrade_to']);
        $this->assertFalse($bulletin['isolated_patches'][0]['applies_to_installed']);
        $this->assertNotContains('2026-sep', $report['apply_order']);

        $verified = $this->security(PatchEvidence::VERIFIED)->build()['open_bulletins'][0];
        $this->assertSame('verified_in_code', $verified['local_status']);
    }

    public function testAnUnavailableLookupStillReportsTheReleaseAndPackages(): void
    {
        $this->watch->method('getVersion')->willReturn(null);
        $this->watch->method('getLastError')->willReturn('magento.watch: timed out');

        $report = $this->security(PatchEvidence::NO_EVIDENCE)->build();

        $this->assertSame('unknown', $report['status']);
        $this->assertSame('magento.watch: timed out', $report['advisories']['reason']);
        $this->assertTrue($report['package_advisories']['available']);
    }

    /**
     * 2.4.9 is tested on PHP 8.5 only, but its metapackage allows 8.3 to 8.5:
     * installable, so a warning rather than a blocker. The extension pinned to
     * 2.4.8's framework is what actually blocks it.
     */
    public function testPhpIsJudgedByTheComposerConstraintAndExtensionsBlock(): void
    {
        $this->watch->method('getVersion')->willReturn($this->wrap($this->p5()));
        $this->watch->method('getSupportedVersions')->willReturn($this->wrap([
            '2.4.8-p4' => ['releaseDate' => '2026-03-10'] + $this->p5(),
            '2.4.8-p5' => $this->p5(),
            '2.4.9' => $this->v249(),
        ]));

        $report = $this->compatibility()->build(null, null, true);
        $candidate = $report['candidates'][0];
        $php = array_column($candidate['components'], null, 'component')['php'];

        $this->assertSame(['2.4.9'], array_column($report['candidates'], 'version'));
        $this->assertTrue($php['installable']);
        $this->assertSame('warning', $php['severity']);
        $this->assertSame(['acme/module-old'], $candidate['blockers']);
        $this->assertSame('103.0.9', $candidate['extensions']['conflicts'][0]['release_requires']);
        $this->assertSame([['package' => 'acme/module-old', 'locked' => '1.2.0', 'latest' => '2.0.0', 'major' => true]], $report['outdated']['outdated']);
    }

    public function testAPhpOutsideTheMetapackageConstraintIsABlocker(): void
    {
        $this->watch->method('getVersion')->willReturnCallback(
            fn (string $distribution, string $version): array => $this->wrap($version === '2.5.0'
                ? ['version' => '2.5.0', 'systemRequirements' => ['php' => ['8.5']]]
                : $this->p5())
        );

        $report = $this->compatibility('~8.5.0')->build('2.5.0');
        $php = array_column($report['candidates'][0]['components'], null, 'component')['php'];

        $this->assertFalse($php['installable']);
        $this->assertSame('not_installable', $php['verdict']);
        $this->assertContains('php', $report['candidates'][0]['blockers']);
    }

    public function testTheInstalledReleaseIsCheckedAgainstItsOwnRequirements(): void
    {
        $this->watch->method('getVersion')->willReturn($this->wrap($this->p5()));
        $this->watch->method('getSupportedVersions')->willReturn($this->wrap([]));

        $current = $this->compatibility()->build()['current'];
        $verdicts = array_column($current['components'], 'verdict', 'component');

        $this->assertSame(['search', 'cache', 'queue'], $current['warnings']);
        $this->assertSame('older_than_tested', $verdicts['search']);
        $this->assertSame('product_not_listed', $verdicts['cache']);
    }

    public function testANamedTargetWithNoDataIsAnError(): void
    {
        $this->watch->method('getVersion')->willReturnCallback(
            fn (string $distribution, string $version): ?array => $version === '2.4.8-p5' ? $this->wrap($this->p5()) : null
        );

        $this->expectException(LocalizedException::class);

        $this->compatibility()->build('9.9.9');
    }

    private function security(string $evidence): SecurityReport
    {
        $patches = $this->createMock(PatchEvidence::class);
        $patches->method('collect')->willReturn(['sources' => [], 'entries' => []]);
        $patches->method('match')->willReturn(['status' => $evidence, 'matches' => []]);
        $advisories = $this->createMock(PackageAdvisories::class);
        $advisories->method('check')->willReturn(['available' => true, 'checked' => 1, 'vulnerable' => []]);

        return new SecurityReport($this->release, $this->watch, $patches, $this->project, $advisories);
    }

    private function compatibility(string $nextPhp = '~8.3.0||~8.4.0||~8.5.0'): CompatibilityReport
    {
        $environment = $this->createMock(EnvironmentVersions::class);
        $environment->method('get')->willReturn(['measured' => [
            ['component' => 'php', 'product' => 'php', 'version' => '8.4.22', 'source' => 'runtime'],
            ['component' => 'database', 'product' => 'mariadb', 'version' => '11.4.10-MariaDB', 'source' => 'database'],
            ['component' => 'search', 'product' => 'opensearch', 'version' => 'Opensearch 2.19.5', 'source' => 'tab'],
            ['component' => 'cache', 'product' => 'redis', 'version' => '8.6.1', 'source' => 'tab'],
            ['component' => 'queue', 'product' => 'rabbitmq', 'version' => '4.1.8', 'source' => 'tab'],
        ], 'unmeasured' => []]);

        $versions = $this->createMock(PackageVersions::class);
        $versions->method('releaseManifest')->willReturnCallback(
            static fn (string $release): array => ['package' => 'magento/product-community-edition', 'require' => $release === '2.4.8-p5'
                ? ['php' => '~8.2.0||~8.3.0||~8.4.0', 'magento/framework' => '103.0.8-p5']
                : ['php' => $nextPhp, 'magento/framework' => '103.0.9']]
        );
        $versions->method('newestStable')->willReturn('2.0.0');

        $constraints = new Constraints();

        return new CompatibilityReport(
            $this->release,
            $this->watch,
            $environment,
            new VersionRequirements(),
            $versions,
            $this->project,
            new ExtensionCompatibility($constraints),
            $constraints
        );
    }

    private function wrap(array $data): array
    {
        return ['data' => $data, 'fetched_at' => '2026-10-13 10:19:18 UTC', 'cached' => false];
    }

    private function p5(): array
    {
        $bulletin = static fn (string $id, string $date, string $patch): array => [
            'id' => $id,
            'severity' => 'critical',
            'publishedDate' => $date,
            'cves' => ['CVE-2026-00000'],
            'fixedIn' => null,
            'isolatedPatches' => [['id' => $patch, 'url' => 'https://example.test']],
        ];

        return [
            'version' => '2.4.8-p5',
            'releaseDate' => '2026-05-12',
            'eolDate' => '2028-04-11',
            'statusLabel' => 'supported',
            'isEOLVersion' => false,
            'isLatestVersion' => false,
            'systemRequirements' => [
                'php' => ['8.3', '8.4'],
                'composer' => ['2.10'],
                'mariadb' => ['11.4', '11.8'],
                'opensearch' => ['3'],
                'rabbitmq' => ['4.3'],
                'valkey' => ['8.1'],
            ],
            'security' => [
                'status' => 'vulnerable',
                'vulnerableTo' => [
                    $bulletin('APSB26-138', '2026-09-08', '2026-sep'),
                    $bulletin('APSB26-146', '2026-09-07', 'VULN-39341'),
                    $bulletin('APSB26-92', '2026-08-11', '2026-aug'),
                    $bulletin('APSB26-73', '2026-07-14', '2026-jul'),
                ],
                'fixedBy' => [['id' => 'APSB26-49', 'fixedIn' => '2.4.8-p5']],
                'recommendedVersion' => '2.4.8-p5',
            ],
        ];
    }

    private function v249(): array
    {
        return [
            'version' => '2.4.9',
            'releaseDate' => '2026-05-12',
            'eolDate' => '2029-05-12',
            'statusLabel' => 'supported (latest)',
            'isLatestVersion' => true,
            'securitySummary' => ['status' => 'vulnerable', 'requiredPatches' => 4, 'upgradeRequired' => false],
            'systemRequirements' => [
                'php' => ['8.5'],
                'mariadb' => ['11.8', '12.3'],
                'opensearch' => ['3'],
                'rabbitmq' => ['4.3'],
                'valkey' => ['9'],
            ],
        ];
    }
}
