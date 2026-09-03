<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Collector;

use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Http\StatusFetcher;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\ResultFactory;
use Magenx\Platform\Model\Metric\Status;

/**
 * Nginx connection counters, from a stub_status endpoint.
 *
 * stub_status emits four lines of plain text and nothing else, so the parsing
 * is a regex rather than a client library. The number worth watching is the
 * gap between accepts and handled: nginx only fails to handle a connection it
 * accepted when it has hit worker_connections, and nothing else in the stack
 * reports that.
 */
class Nginx implements CollectorInterface
{
    private StatusFetcher $fetcher;

    private Config $config;

    private ResultFactory $resultFactory;

    private Formatter $formatter;

    /**
     * @param StatusFetcher $fetcher
     * @param Config $config
     * @param ResultFactory $resultFactory
     * @param Formatter $formatter
     */
    public function __construct(
        StatusFetcher $fetcher,
        Config $config,
        ResultFactory $resultFactory,
        Formatter $formatter
    ) {
        $this->fetcher = $fetcher;
        $this->config = $config;
        $this->resultFactory = $resultFactory;
        $this->formatter = $formatter;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Nginx';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        /** @var Result $result */
        $result = $this->resultFactory->create();

        $url = $this->config->getNginxStatusUrl();
        if ($url === '') {
            return $result->setStatus(Status::UNAVAILABLE)->setSummary(
                'No status URL is configured. Set one in Stores > Configuration > Magenx > Platform Overview, '
                . 'pointing at an nginx location that runs stub_status.'
            );
        }

        // The endpoint URL rides in the summary line rather than in a card of
        // its own: a one-row card for a value the admin typed into config is
        // furniture, not a metric. It is shown redacted, because a stub_status
        // location behind basic auth is commonly configured as
        // http://user:password@nginx/nginx_status and the summary line is not a
        // place to publish that password.
        $shownUrl = $this->fetcher->redact($url);

        $body = $this->fetcher->fetch($url);
        if ($body === null) {
            return $result->setStatus(Status::UNAVAILABLE)
                ->setSummary(sprintf('%s did not answer (%s).', $shownUrl, $this->fetcher->getLastError()));
        }

        $stats = $this->parse($body);
        if ($stats === null) {
            return $result->setStatus(Status::UNAVAILABLE)->setSummary(
                sprintf(
                    '%s answered, but not with stub_status output. Check that the location uses "stub_status;".',
                    $shownUrl
                )
            );
        }

        $result->setSummary(
            sprintf('%s active connections — %s', $this->formatter->number($stats['active']), $shownUrl)
        );
        $this->addRows($result, $stats);

        return $result;
    }

    /**
     * @param Result $result
     * @param array $stats
     * @return void
     */
    private function addRows(Result $result, array $stats): void
    {
        $section = 'Connections';
        $dropped = $stats['accepts'] - $stats['handled'];

        $result->add($section, 'Active', $this->formatter->number($stats['active']));
        $result->add(
            $section,
            'Dropped',
            $this->formatter->number($dropped),
            $dropped > 0 ? Status::ERROR : Status::OK,
            'Accepted but never handled — nginx hit worker_connections and closed them.'
        );
        $result->add($section, 'Accepted', $this->formatter->number($stats['accepts']));
        $result->add($section, 'Handled', $this->formatter->number($stats['handled']));
        $result->add($section, 'Requests', $this->formatter->number($stats['requests']));
        $result->add(
            $section,
            'Requests per Connection',
            $stats['handled'] > 0 ? sprintf('%.1f', $stats['requests'] / $stats['handled']) : 'n/a',
            Status::INFO,
            'Close to 1 means keep-alive is not being reused.'
        );

        $section = 'Worker State';
        $result->add($section, 'Reading', $this->formatter->number($stats['reading']), Status::INFO, 'Reading the request header.');
        $result->add(
            $section,
            'Writing',
            $this->formatter->number($stats['writing']),
            Status::INFO,
            'Waiting on an upstream or streaming a response — this is where a slow PHP tier shows up.'
        );
        $result->add($section, 'Waiting', $this->formatter->number($stats['waiting']), Status::INFO, 'Idle keep-alive connections.');
    }

    /**
     * Parse the fixed four-line stub_status body.
     *
     * @param string $body
     * @return array|null
     */
    private function parse(string $body): ?array
    {
        $matched = preg_match(
            '/Active connections:\s*(\d+).*?(\d+)\s+(\d+)\s+(\d+).*?'
            . 'Reading:\s*(\d+)\s+Writing:\s*(\d+)\s+Waiting:\s*(\d+)/s',
            $body,
            $matches
        );

        if ($matched !== 1) {
            return null;
        }

        return [
            'active' => (int) $matches[1],
            'accepts' => (int) $matches[2],
            'handled' => (int) $matches[3],
            'requests' => (int) $matches[4],
            'reading' => (int) $matches[5],
            'writing' => (int) $matches[6],
            'waiting' => (int) $matches[7],
        ];
    }
}
