<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Http;

use Magenx\Platform\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * The one place this module makes an outbound HTTP request.
 *
 * Every call is bounded by the configured timeout, refuses to follow redirects,
 * and accepts only http/https — the URLs come from admin config, which is
 * ACL-gated, but a status probe still has no business speaking file:// or
 * gopher:// on the strength of a typo.
 */
class StatusFetcher
{
    private CurlFactory $curlFactory;

    private Config $config;

    private string $lastError = '';

    /**
     * @param CurlFactory $curlFactory
     * @param Config $config
     */
    public function __construct(CurlFactory $curlFactory, Config $config)
    {
        $this->curlFactory = $curlFactory;
        $this->config = $config;
    }

    /**
     * Fetch a status endpoint, or null if it did not answer with a 2xx.
     *
     * @param string $url
     * @param string|null $user
     * @param string|null $password
     * @return string|null
     */
    public function fetch(string $url, ?string $user = null, ?string $password = null): ?string
    {
        $this->lastError = '';

        // A scheme prefix test rather than the URL-parsing helper the Magento
        // standard discourages: only http and https may be probed. The URLs
        // come from admin config, which is ACL-gated, but a status probe still
        // has no business speaking file:// on the strength of a typo.
        if (preg_match('#^https?://#i', $url) !== 1) {
            $this->lastError = 'Only http and https URLs can be probed.';

            return null;
        }

        $timeout = $this->config->getTimeout();

        try {
            $curl = $this->curlFactory->create();
            $curl->setOptions(
                [
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_FOLLOWLOCATION => false,
                ]
            );
            $curl->addHeader('Accept', 'application/json, text/plain, */*');
            if ($user !== null && $user !== '') {
                $curl->setCredentials($user, (string) $password);
            }
            $curl->get($url);
            $status = $curl->getStatus();
            $body = $curl->getBody();
        } catch (\Throwable $e) {
            // curl reports the URL it was given back in some of its errors, and
            // that URL may carry userinfo, so this goes through redact() too.
            $this->lastError = $this->redact($e->getMessage());

            return null;
        }

        if ($status < 200 || $status >= 300) {
            $this->lastError = sprintf('HTTP %d from %s', $status, $this->redact($url));

            return null;
        }

        return $body;
    }

    /**
     * Why the last fetch() returned null.
     *
     * @return string
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Strip any userinfo before a URL goes into a message the admin will read.
     *
     * Public because the collectors render configured endpoint URLs themselves —
     * on a summary line or in an "Endpoint" row — and an admin who pasted
     * http://user:password@host into configuration must not get that password
     * back out on the page. One implementation, used by every caller that puts a
     * URL in front of a human.
     *
     * @param string $url
     * @return string
     */
    public function redact(string $url): string
    {
        // Greedy up to the LAST "@" before the path, because that is the one
        // curl splits on: in http://user:p@ssw0rd@host a lazy class stops at
        // the first "@" and hands back http://ssw0rd@host — publishing most of
        // the password while curl authenticates with all of it. Excluding "/"
        // is what keeps the match inside the authority, so an "@" in a path or
        // query is still left alone.
        return (string) preg_replace('#://[^/\s]*@#', '://', $url);
    }
}
