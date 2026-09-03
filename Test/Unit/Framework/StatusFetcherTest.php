<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework;

use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Http\StatusFetcher;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Magenx\Platform\Model\Http\StatusFetcher
 */
class StatusFetcherTest extends TestCase
{
    private CurlFactory&MockObject $curlFactory;

    private StatusFetcher $fetcher;

    protected function setUp(): void
    {
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->fetcher = new StatusFetcher($this->curlFactory, $this->createMock(Config::class));
    }

    /**
     * @dataProvider rejectedSchemeProvider
     */
    public function testOnlyHttpAndHttpsMayBeProbed(string $url): void
    {
        // The URLs come from admin config, which is ACL-gated, but a status
        // probe has no business speaking file:// on the strength of a typo. The
        // scheme is checked before a handle is ever created.
        $this->curlFactory->expects($this->never())->method('create');

        $this->assertNull($this->fetcher->fetch($url));
        $this->assertSame('Only http and https URLs can be probed.', $this->fetcher->getLastError());
    }

    public static function rejectedSchemeProvider(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://nginx/'],
            'ftp' => ['ftp://nginx/'],
            'no scheme' => ['nginx/nginx_status'],
            'scheme not at the start' => ['/redirect?to=http://nginx'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider acceptedSchemeProvider
     */
    public function testHttpAndHttpsPassTheSchemeCheck(string $url): void
    {
        // Reaching the factory is the assertion: the scheme gate let it through.
        $this->curlFactory->expects($this->once())
            ->method('create')
            ->willThrowException(new \RuntimeException('stop here'));

        $this->assertNull($this->fetcher->fetch($url));
    }

    public static function acceptedSchemeProvider(): array
    {
        return [
            'http' => ['http://nginx/nginx_status'],
            'https' => ['https://nginx/nginx_status'],
            'mixed case' => ['HtTp://nginx/nginx_status'],
        ];
    }

    /**
     * @dataProvider redactProvider
     */
    public function testRedactStripsUserinfo(string $url, string $expected): void
    {
        // An admin who pasted http://user:password@host into configuration must
        // not get that password back out on the page — the collectors render
        // these URLs on a summary line or in an "Endpoint" row.
        $this->assertSame($expected, $this->fetcher->redact($url));
    }

    public static function redactProvider(): array
    {
        return [
            'user and password' => ['http://user:pass@nginx/nginx_status', 'http://nginx/nginx_status'],
            'user only' => ['http://user@nginx/nginx_status', 'http://nginx/nginx_status'],
            'nothing to redact' => ['http://nginx/nginx_status', 'http://nginx/nginx_status'],
            // The pattern must not cross a slash, or an @ in the path would
            // take the host with it.
            'an at sign in the path is left alone' => ['http://nginx/a@b', 'http://nginx/a@b'],
            'port survives' => ['http://user:pass@imgproxy:4594/metrics', 'http://imgproxy:4594/metrics'],
            'https' => ['https://user:pass@rabbitmq:15672', 'https://rabbitmq:15672'],
        ];
    }

    public function testCurlFailuresAreReportedRedacted(): void
    {
        // curl echoes the URL it was given back in some of its errors, and that
        // URL may carry userinfo.
        $this->curlFactory->expects($this->once())
            ->method('create')
            ->willThrowException(new \RuntimeException('could not resolve http://user:pass@nginx/'));

        $this->assertNull($this->fetcher->fetch('http://user:pass@nginx/'));
        $this->assertStringNotContainsString('pass', $this->fetcher->getLastError());
        $this->assertSame('could not resolve http://nginx/', $this->fetcher->getLastError());
    }

    public function testTheErrorIsClearedAtTheStartOfEachFetch(): void
    {
        $this->assertNull($this->fetcher->fetch('file:///etc/passwd'));
        $this->assertNotSame('', $this->fetcher->getLastError());

        $this->curlFactory->method('create')->willThrowException(new \RuntimeException('later failure'));

        $this->assertNull($this->fetcher->fetch('http://nginx/'));
        $this->assertSame('later failure', $this->fetcher->getLastError());
    }
}
