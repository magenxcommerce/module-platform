<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

use Magenx\Platform\Model\Config;
use Magento\Framework\Exception\LocalizedException;

/**
 * Releases, open bulletins and system requirements, from the magento.watch API.
 *
 * magento.watch is an independent, community-run index of Adobe's security
 * bulletins and system requirements, keyed by release — the one place that
 * answers "which bulletins are still open against 2.4.8-p5, and which isolated
 * patch closes each" without scraping Adobe's pages. It needs no key, and what
 * it is sent is the release string in the request path.
 */
class MagentoWatch
{
    /** The distributions the API indexes, as it spells them. */
    public const DISTRIBUTIONS = ['magento-community', 'magento-commerce', 'mage-os'];

    private RemoteJson $remote;

    private Config $config;

    private string $lastError = '';

    /**
     * @param RemoteJson $remote
     * @param Config $config
     */
    public function __construct(RemoteJson $remote, Config $config)
    {
        $this->remote = $remote;
        $this->config = $config;
    }

    /**
     * One release: lifecycle dates, system requirements and its `security`
     * block — open bulletins with their isolated patches, and the bulletins the
     * release itself fixed.
     *
     * @param string $distribution
     * @param string $version
     * @return array{data: array<string, mixed>, fetched_at: string, cached: bool}|null
     * @throws LocalizedException When the distribution or version is malformed.
     */
    public function getVersion(string $distribution, string $version): ?array
    {
        return $this->get($this->assertDistribution($distribution) . '/versions/' . $this->assertVersion($version));
    }

    /**
     * Every release still in support, keyed by version, each with its
     * requirements and a `securitySummary`.
     *
     * @param string $distribution
     * @return array{data: array<string, mixed>, fetched_at: string, cached: bool}|null
     * @throws LocalizedException When the distribution is not one the API indexes.
     */
    public function getSupportedVersions(string $distribution): ?array
    {
        return $this->get($this->assertDistribution($distribution) . '/versions/supported');
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
     * @param string $path
     * @return array{data: array<string, mixed>, fetched_at: string, cached: bool}|null
     */
    private function get(string $path): ?array
    {
        $response = $this->remote->get(
            rtrim($this->config->getReleaseApiUrl(), '/') . '/' . $path,
            static fn (array $body): ?array => is_array($body['data'] ?? null) ? $body['data'] : null
        );
        if ($response === null) {
            $this->lastError = 'magento.watch: ' . $this->remote->getLastError();

            return null;
        }
        if (!is_array($response['data'])) {
            $this->lastError = sprintf('magento.watch answered without data for %s', $path);

            return null;
        }
        $this->lastError = '';

        return $response;
    }

    /**
     * @param string $distribution
     * @return string
     * @throws LocalizedException
     */
    private function assertDistribution(string $distribution): string
    {
        if (!in_array($distribution, self::DISTRIBUTIONS, true)) {
            throw new LocalizedException(__(
                'Unknown distribution "%1". Use one of: %2.',
                $distribution,
                implode(', ', self::DISTRIBUTIONS)
            ));
        }

        return $distribution;
    }

    /**
     * A release string safe to put in a URL path: digits first, then only the
     * characters release names use. Anything else would be a path the API
     * never published, or one that leaves the versions resource entirely.
     *
     * @param string $version
     * @return string
     * @throws LocalizedException
     */
    private function assertVersion(string $version): string
    {
        if (preg_match('/^\d[0-9A-Za-z.\-]{0,39}$/', $version) !== 1 || str_contains($version, '..')) {
            throw new LocalizedException(__('"%1" is not a release version such as 2.4.8-p5.', $version));
        }

        return $version;
    }
}
