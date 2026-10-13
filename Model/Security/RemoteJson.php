<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

use Magenx\Platform\Model\Cache\Type\Snapshot;
use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Http\JsonFetcher;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Cached JSON from the public services the Security and Compatibility tabs ask.
 *
 * Three rules every one of those requests shares, so no caller can forget one:
 *
 * - **https only.** What comes back decides whether a store is reported as
 *   patched or an upgrade as installable; that is not taken over plain HTTP.
 * - **An hour in the Platform cache**, which is what the services themselves
 *   advertise. A failed request is not kept, so the next load retries. A
 *   caller may pass a reducer, and then only the reduced value is kept — the
 *   Mage-OS manifest of every release is 1.4 MB, of which one release's
 *   requirements are wanted.
 * - **One unreachable host costs one timeout.** A host that fails to answer at
 *   all is skipped for the rest of the request, so forty package lookups
 *   against a firewalled Packagist take one timeout, not forty. An HTTP error
 *   — a 404 for a private package — or an answer that is not JSON says
 *   nothing against the host and does not trip this.
 *
 * `bin/magento cache:clean magenx_platform` forgets everything, as it does for
 * the tabs' own snapshots.
 */
class RemoteJson
{
    private const CACHE_PREFIX = 'magenx_platform_remote_';

    private const CACHE_TTL = 3600;

    /**
     * Public metadata files run to megabytes; the probe timeout is sized for a
     * status endpoint on the internal network, not for them.
     */
    private const MIN_TIMEOUT = 10;

    private JsonFetcher $fetcher;

    private Snapshot $cache;

    private Json $json;

    private Config $config;

    private string $lastError = '';

    /** @var array<string, string> Host => why it was given up on, for this request. */
    private array $downHosts = [];

    /** @var array<string, array<mixed>> URL => decoded body, for this request, when reduced by variant. */
    private array $bodies = [];

    /**
     * @param JsonFetcher $fetcher
     * @param Snapshot $cache
     * @param Json $json
     * @param Config $config
     */
    public function __construct(JsonFetcher $fetcher, Snapshot $cache, Json $json, Config $config)
    {
        $this->fetcher = $fetcher;
        $this->cache = $cache;
        $this->json = $json;
        $this->config = $config;
    }

    /**
     * Fetch, or answer from cache, or null with getLastError() saying why.
     *
     * @param string $url
     * @param callable|null $reduce Applied to the decoded body before it is cached and returned.
     * @param string $variant Names what the reducer keeps, when one URL is reduced more than one way.
     * @return array{data: mixed, fetched_at: string, cached: bool}|null
     */
    public function get(string $url, ?callable $reduce = null, string $variant = ''): ?array
    {
        $this->lastError = '';

        if (!$this->config->isAdvisoryLookupEnabled()) {
            $this->lastError = 'public advisory lookups are switched off under Stores > Configuration > Magenx > '
                . 'Platform Overview > Security & Compatibility';

            return null;
        }

        if (preg_match('~^https://([^/\s?#]+)~i', $url, $match) !== 1) {
            $this->lastError = 'only https:// URLs are fetched';

            return null;
        }
        $host = strtolower($match[1]);

        $key = self::CACHE_PREFIX . hash('sha256', $url . "\n" . $variant);
        $cached = $this->cache->load($key);
        if (is_string($cached) && $cached !== '') {
            try {
                $payload = $this->json->unserialize($cached);
            } catch (\InvalidArgumentException) {
                $payload = null;
            }
            if (is_array($payload) && array_key_exists('data', $payload)) {
                return ['data' => $payload['data'], 'fetched_at' => (string) $payload['fetched_at'], 'cached' => true];
            }
        }

        if (isset($this->downHosts[$host])) {
            $this->lastError = sprintf(
                '%s did not answer earlier in this request (%s)',
                $host,
                $this->downHosts[$host]
            );

            return null;
        }

        $body = $this->bodies[$url] ?? $this->fetcher->fetch(
            $url,
            null,
            null,
            max(self::MIN_TIMEOUT, $this->config->getTimeout())
        );
        if ($body === null) {
            $this->lastError = $this->fetcher->getLastError();
            if ($this->isTransportFailure($this->lastError)) {
                $this->downHosts[$host] = $this->lastError;
            }

            return null;
        }
        if ($variant !== '') {
            // The same document reduced another way later in this request —
            // two releases from one manifest — is not downloaded twice.
            $this->bodies[$url] = $body;
        }

        $payload = [
            'data' => $reduce === null ? $body : $reduce($body),
            'fetched_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];
        $this->cache->save($this->json->serialize($payload), $key, [], self::CACHE_TTL);

        return $payload + ['cached' => false];
    }

    /**
     * Why the last get() returned null.
     *
     * @return string
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Whether a failure means the host did not answer at all.
     *
     * An HTTP error status, or an answer that is not the JSON expected, came
     * from a host that is up: a private package is a 404, not an outage.
     *
     * @param string $error
     * @return bool
     */
    private function isTransportFailure(string $error): bool
    {
        return !str_starts_with($error, 'HTTP ') && !str_starts_with($error, 'the endpoint answered');
    }
}
