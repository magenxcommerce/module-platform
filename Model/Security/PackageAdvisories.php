<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

use Magenx\Platform\Model\Config;

/**
 * Known vulnerabilities in the locked packages — what `composer audit` reports,
 * without running composer.
 *
 * Packagist publishes the same advisory database `composer audit` reads
 * (GitHub's and FriendsOfPHP's, merged) at /api/security-advisories/, queried
 * by package name. Names go out in batches, each batch is cached for an hour,
 * and each advisory's affected range is checked against the locked version
 * here, with Composer's own constraint rules.
 *
 * Adobe's bulletins against Magento itself are not repeated here: they are
 * keyed to the release rather than to magento/* packages, and the release
 * section of the Security tab reports them.
 */
class PackageAdvisories
{
    /** Names per request: keeps the query string well inside what servers accept. */
    private const BATCH = 40;

    private RemoteJson $remote;

    private Config $config;

    private Constraints $constraints;

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
     * The locked packages an advisory's affected range covers.
     *
     * @param array<string, array<string, mixed>> $packages {@see ComposerProject::lockedPackages()}
     * @return array{available: bool, reason?: string, checked: int, vulnerable: array<int, array<string, mixed>>}
     */
    public function check(array $packages): array
    {
        $base = rtrim($this->config->getPackagistUrl(), '/') . '/api/security-advisories/?';
        $vulnerable = [];
        $checked = 0;
        $failure = null;

        foreach (array_chunk(array_keys($packages), self::BATCH) as $names) {
            $query = implode('&', array_map(
                static fn (string $name): string => 'packages[]=' . rawurlencode($name),
                $names
            ));
            $response = $this->remote->get($base . $query, [$this, 'reduce']);
            if ($response === null) {
                $failure = $this->remote->getLastError();
                continue;
            }
            $checked += count($names);

            foreach ((array) $response['data'] as $name => $advisories) {
                $package = $packages[strtolower((string) $name)] ?? null;
                if ($package === null) {
                    continue;
                }
                $matching = array_values(array_filter(
                    (array) $advisories,
                    fn ($advisory): bool => is_array($advisory)
                        && $this->constraints->satisfies($package['version'], (string) $advisory['affected']) === true
                ));
                if ($matching !== []) {
                    $vulnerable[] = [
                        'package' => $package['name'],
                        'version' => $package['version'],
                        'advisories' => $matching,
                    ];
                }
            }
        }

        $result = ['available' => $checked > 0, 'checked' => $checked, 'vulnerable' => $vulnerable];
        if ($failure !== null) {
            $result['reason'] = 'Packagist: ' . $failure;
        }

        return $result;
    }

    /**
     * Keep the fields a report shows, keyed by package; drop the rest before
     * the answer is cached.
     *
     * @param array<mixed> $body
     * @return array<string, array<int, array<string, string|null>>>
     */
    public function reduce(array $body): array
    {
        $reduced = [];
        foreach ((array) ($body['advisories'] ?? []) as $name => $advisories) {
            foreach ((array) $advisories as $advisory) {
                if (!is_array($advisory) || !is_string($advisory['affectedVersions'] ?? null)) {
                    continue;
                }
                $reduced[(string) $name][] = [
                    'id' => (string) ($advisory['remoteId'] ?? $advisory['advisoryId'] ?? ''),
                    'cve' => is_string($advisory['cve'] ?? null) ? $advisory['cve'] : null,
                    'title' => (string) ($advisory['title'] ?? ''),
                    'severity' => is_string($advisory['severity'] ?? null) ? strtolower($advisory['severity']) : null,
                    'affected' => $advisory['affectedVersions'],
                    'link' => is_string($advisory['link'] ?? null) ? $advisory['link'] : null,
                ];
            }
        }

        return $reduced;
    }
}
