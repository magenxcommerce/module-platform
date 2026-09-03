<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Http;

use Magento\Framework\Serialize\Serializer\Json;

/**
 * A status endpoint that answers JSON.
 *
 * The OpenSearch and RabbitMQ collectors had the same private getJson() —
 * fetch, reject an empty body, decode inside a try, insist on an array — and
 * each carried a Json dependency to get there. One implementation instead, so
 * "answered with something that is not JSON" reads the same on both tabs.
 *
 * It also gives those cases a reason. StatusFetcher only records an error when
 * the request itself failed, so a 200 carrying an HTML login page used to
 * surface as "did not answer ()" with nothing in the brackets.
 */
class JsonFetcher
{
    private StatusFetcher $fetcher;

    private Json $json;

    private string $lastError = '';

    /**
     * @param StatusFetcher $fetcher
     * @param Json $json
     */
    public function __construct(StatusFetcher $fetcher, Json $json)
    {
        $this->fetcher = $fetcher;
        $this->json = $json;
    }

    /**
     * Fetch and decode, or null with getLastError() explaining why not.
     *
     * @param string $url
     * @param string|null $user
     * @param string|null $password
     * @return array|null
     */
    public function fetch(string $url, ?string $user = null, ?string $password = null): ?array
    {
        $this->lastError = '';

        $body = $this->fetcher->fetch($url, $user, $password);
        if ($body === null) {
            $this->lastError = $this->fetcher->getLastError();

            return null;
        }

        if ($body === '') {
            $this->lastError = 'the endpoint answered with an empty body';

            return null;
        }

        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException) {
            $this->lastError = 'the endpoint answered, but not with JSON';

            return null;
        }

        if (!is_array($decoded)) {
            $this->lastError = 'the endpoint answered with JSON that is not an object or a list';

            return null;
        }

        return $decoded;
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
     * Strip userinfo from a URL before it is shown to an admin.
     *
     * Proxied so a collector holding this needs no second dependency just to
     * render the endpoint it was pointed at. See StatusFetcher::redact().
     *
     * @param string $url
     * @return string
     */
    public function redact(string $url): string
    {
        return $this->fetcher->redact($url);
    }
}
