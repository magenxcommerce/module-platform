<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

use Magenx\Platform\Model\Config;

/**
 * Package metadata from public composer repositories.
 *
 * Two questions, both answered from the composer v2 metadata files
 * (`/p2/vendor/package.json`) that composer itself downloads:
 *
 * - **The newest stable version of a package.** magento/* packages are read
 *   from the Mage-OS mirror, which republishes repo.magento.com without
 *   credentials; everything else from Packagist. A package from a private
 *   repository is found on neither and is reported as unknown, not current.
 * - **What a Magento release installs.** The product metapackage pins every
 *   magento/* module to an exact version and constrains PHP and every library
 *   — the real answer to "can this store install 2.4.9", which a list of
 *   tested PHP versions is not. Only the Open Source metapackage is public;
 *   for Adobe Commerce it stands in for the modules both editions share.
 */
class PackageVersions
{
    private const RELEASE_PACKAGE = 'magento/product-community-edition';

    private RemoteJson $remote;

    private Config $config;

    private Constraints $constraints;

    private string $lastError = '';

    /**
     * @param RemoteJson $remote
     * @param Config $config
     * @param Constraints $constraints
     */
    public function __construct(RemoteJson $remote, Config $config, Constraints $constraints)
    {
        $this->remote = $remote;
        $this->config = $config;
        $this->constraints = $constraints;
    }

    /**
     * @param string $package
     * @return string|null
     */
    public function newestStable(string $package): ?string
    {
        $package = strtolower($package);
        $url = $this->metadataUrl($package);
        if ($url === null) {
            $this->lastError = 'not a composer package name';

            return null;
        }

        $response = $this->remote->get($url, function (array $body) use ($package): ?string {
            $versions = array_column((array) ($body['packages'][$package] ?? []), 'version');

            return $this->constraints->newestStable($versions);
        });

        if ($response === null) {
            $this->lastError = $this->remote->getLastError();

            return null;
        }
        if (!is_string($response['data'])) {
            $this->lastError = 'no stable release is published';

            return null;
        }
        $this->lastError = '';

        return $response['data'];
    }

    /**
     * The `require` map of the Magento product metapackage at one release.
     *
     * @param string $release e.g. "2.4.9"
     * @return array{package: string, require: array<string, string>}|null
     */
    public function releaseManifest(string $release): ?array
    {
        $response = $this->remote->get(
            (string) $this->metadataUrl(self::RELEASE_PACKAGE),
            static function (array $body) use ($release): ?array {
                foreach ((array) ($body['packages'][self::RELEASE_PACKAGE] ?? []) as $entry) {
                    if (is_array($entry) && ($entry['version'] ?? null) === $release) {
                        return array_filter((array) ($entry['require'] ?? []), 'is_string');
                    }
                }

                return null;
            },
            $release
        );

        if ($response === null) {
            $this->lastError = $this->remote->getLastError();

            return null;
        }
        if (!is_array($response['data'])) {
            $this->lastError = sprintf('%s %s is not published', self::RELEASE_PACKAGE, $release);

            return null;
        }
        $this->lastError = '';

        return ['package' => self::RELEASE_PACKAGE, 'require' => $response['data']];
    }

    /**
     * Why the last lookup returned null.
     *
     * @return string
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * The metadata URL for a package, or null for a name composer would not
     * accept — which is also what keeps a name from becoming another path.
     *
     * @param string $package
     * @return string|null
     */
    private function metadataUrl(string $package): ?string
    {
        if (preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $package) !== 1) {
            return null;
        }

        $base = str_starts_with($package, 'magento/')
            ? $this->config->getMagentoRepoUrl()
            : $this->config->getPackagistRepoUrl();

        return rtrim($base, '/') . '/p2/' . $package . '.json';
    }
}
