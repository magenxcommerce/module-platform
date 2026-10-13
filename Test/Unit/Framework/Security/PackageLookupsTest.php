<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework\Security;

use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Security\Constraints;
use Magenx\Platform\Model\Security\ExtensionCompatibility;
use Magenx\Platform\Model\Security\PackageAdvisories;
use Magenx\Platform\Model\Security\PackageVersions;
use Magenx\Platform\Model\Security\RemoteJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The composer.lock checks: advisories against locked versions, the newest
 * stable release of a package, a Magento release's manifest, and which
 * extensions that manifest would refuse. The remote answers are shaped like
 * the real Packagist and Mage-OS responses, and run through each class's own
 * reducer, so what is cached is what is tested.
 */
#[CoversClass(PackageAdvisories::class)]
#[CoversClass(PackageVersions::class)]
#[CoversClass(ExtensionCompatibility::class)]
class PackageLookupsTest extends TestCase
{
    private Config&MockObject $config;

    /** @var string[] */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getPackagistUrl')->willReturn('https://packagist.org');
        $this->config->method('getPackagistRepoUrl')->willReturn('https://repo.packagist.org');
        $this->config->method('getMagentoRepoUrl')->willReturn('https://mirror.mage-os.org');
    }

    public function testAnAdvisoryCountsOnlyWhenItsRangeCoversTheLockedVersion(): void
    {
        $remote = $this->remote(static fn (string $url): array => ['advisories' => [
            'guzzlehttp/guzzle' => [
                [
                    'remoteId' => 'GHSA-v5mv-p594-2x33',
                    'cve' => 'CVE-2026-69246',
                    'title' => 'Noncanonical host can bypass host-based checks',
                    'severity' => 'high',
                    'affectedVersions' => '>=8.0.0,<8.0.1|<7.15.2',
                    'link' => 'https://github.com/advisories/GHSA-v5mv-p594-2x33',
                ],
            ],
            'stripe/stripe-php' => [
                ['remoteId' => 'GHSA-old', 'affectedVersions' => '<10.0.0', 'title' => 'Old', 'severity' => 'low'],
            ],
        ]]);

        $result = (new PackageAdvisories($remote, $this->config, new Constraints()))->check([
            'guzzlehttp/guzzle' => ['name' => 'guzzlehttp/guzzle', 'version' => '7.9.2', 'require' => []],
            'stripe/stripe-php' => ['name' => 'stripe/stripe-php', 'version' => 'v17.1.0', 'require' => []],
        ]);

        $this->assertTrue($result['available']);
        $this->assertSame(2, $result['checked']);
        $this->assertSame(['guzzlehttp/guzzle'], array_column($result['vulnerable'], 'package'));
        $this->assertSame('CVE-2026-69246', $result['vulnerable'][0]['advisories'][0]['cve']);
        $this->assertStringContainsString('packages[]=guzzlehttp%2Fguzzle', $this->requested[0]);
    }

    public function testTheNewestStableComesFromPackagistOrTheMagentoMirror(): void
    {
        $remote = $this->remote(static fn (string $url): array => str_contains($url, 'mirror.mage-os.org')
            ? ['packages' => ['magento/module-foo' => [['version' => '100.4.9'], ['version' => '100.5.0-beta1']]]]
            : ['packages' => ['stripe/stripe-php' => [['version' => 'v22.1.0-beta.1'], ['version' => 'v22.0.0']]]]);
        $versions = new PackageVersions($remote, $this->config, new Constraints());

        $this->assertSame('v22.0.0', $versions->newestStable('stripe/stripe-php'));
        $this->assertSame('100.4.9', $versions->newestStable('magento/module-foo'));
        $this->assertSame(
            ['https://repo.packagist.org/p2/stripe/stripe-php.json', 'https://mirror.mage-os.org/p2/magento/module-foo.json'],
            $this->requested
        );
        $this->assertNull($versions->newestStable('../../etc/passwd'));
    }

    public function testAReleaseManifestIsTheMetapackageRequireAtThatVersion(): void
    {
        $remote = $this->remote(static fn (string $url): array => ['packages' => [
            'magento/product-community-edition' => [
                ['version' => '2.4.8-p5', 'require' => ['php' => '~8.2.0||~8.3.0||~8.4.0']],
                ['version' => '2.4.9', 'require' => ['php' => '~8.3.0||~8.4.0||~8.5.0', 'magento/framework' => '103.0.9']],
            ],
        ]]);
        $versions = new PackageVersions($remote, $this->config, new Constraints());

        $manifest = $versions->releaseManifest('2.4.9');

        $this->assertSame('~8.3.0||~8.4.0||~8.5.0', $manifest['require']['php']);
        $this->assertNull($versions->releaseManifest('2.5.0'));
        $this->assertStringContainsString('not published', $versions->getLastError());
    }

    public function testAnExtensionThatExcludesWhatTheReleasePinsIsAConflict(): void
    {
        $result = (new ExtensionCompatibility(new Constraints()))->check(
            [
                'acme/module-old' => [
                    'name' => 'Acme/module-old',
                    'version' => '1.2.0',
                    'require' => ['magento/framework' => '102.0.*', 'php' => '~7.4.0'],
                ],
                'acme/module-guzzle' => [
                    'name' => 'acme/module-guzzle',
                    'version' => '2.0.0',
                    'require' => ['guzzlehttp/guzzle' => '^6.5'],
                ],
                'acme/module-fine' => [
                    'name' => 'acme/module-fine',
                    'version' => '3.0.0',
                    'require' => ['magento/framework' => '^103.0', 'guzzlehttp/guzzle' => '^7.0'],
                ],
                'magento/module-catalog' => ['name' => 'magento/module-catalog', 'version' => '104.0.8-p5', 'require' => []],
                'guzzlehttp/guzzle' => ['name' => 'guzzlehttp/guzzle', 'version' => '7.9.2', 'require' => []],
            ],
            ['magento/framework' => '103.0.9', 'guzzlehttp/guzzle' => '^7.5', 'php' => '~8.3.0||~8.4.0||~8.5.0']
        );

        $this->assertSame(3, $result['checked'], 'Magento\'s own packages and its libraries move with it.');
        $this->assertSame(
            [['Acme/module-old', 'magento/framework'], ['acme/module-guzzle', 'guzzlehttp/guzzle']],
            array_map(static fn (array $c): array => [$c['package'], $c['requires']], $result['conflicts'])
        );
    }

    private function remote(callable $respond): RemoteJson&MockObject
    {
        $remote = $this->createMock(RemoteJson::class);
        $remote->method('get')->willReturnCallback(
            function (string $url, ?callable $reduce = null) use ($respond): array {
                $this->requested[] = $url;
                $body = $respond($url);

                return ['data' => $reduce === null ? $body : $reduce($body), 'fetched_at' => 'now', 'cached' => false];
            }
        );

        return $remote;
    }
}
