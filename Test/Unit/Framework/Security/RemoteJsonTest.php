<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework\Security;

use Magenx\Platform\Model\Cache\Type\Snapshot;
use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Http\JsonFetcher;
use Magenx\Platform\Model\Security\RemoteJson;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The rules every public lookup shares: switched off means silent, https only,
 * an hour in cache with only the reduced value kept, and one dead host costing
 * one timeout per request rather than one per lookup.
 */
#[CoversClass(RemoteJson::class)]
class RemoteJsonTest extends TestCase
{
    private JsonFetcher&MockObject $fetcher;

    private Snapshot&MockObject $cache;

    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->fetcher = $this->createMock(JsonFetcher::class);
        $this->cache = $this->createMock(Snapshot::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getTimeout')->willReturn(3);
    }

    public function testSwitchedOffNothingIsSent(): void
    {
        $this->config->method('isAdvisoryLookupEnabled')->willReturn(false);
        $this->fetcher->expects($this->never())->method('fetch');

        $remote = $this->remote();

        $this->assertNull($remote->get('https://magento.watch/api/v1/health'));
        $this->assertStringContainsString('switched off', $remote->getLastError());
    }

    public function testPlainHttpIsRefused(): void
    {
        $this->config->method('isAdvisoryLookupEnabled')->willReturn(true);
        $this->fetcher->expects($this->never())->method('fetch');

        $this->assertNull($this->remote()->get('http://magento.watch/api/v1/health'));
    }

    public function testOnlyTheReducedValueIsCachedForAnHourWithAGenerousTimeout(): void
    {
        $this->config->method('isAdvisoryLookupEnabled')->willReturn(true);
        $this->fetcher->expects($this->once())
            ->method('fetch')
            ->with('https://repo.packagist.org/p2/a/b.json', null, null, 10)
            ->willReturn(['packages' => ['a/b' => [['version' => '1.0.0']]], 'noise' => str_repeat('x', 100)]);
        $this->cache->expects($this->once())
            ->method('save')
            ->with(
                $this->callback(static fn (string $saved): bool => !str_contains($saved, 'noise')),
                $this->stringStartsWith('magenx_platform_remote_'),
                [],
                3600
            );

        $result = $this->remote()->get(
            'https://repo.packagist.org/p2/a/b.json',
            static fn (array $body): string => $body['packages']['a/b'][0]['version']
        );

        $this->assertSame('1.0.0', $result['data']);
        $this->assertFalse($result['cached']);
    }

    public function testACachedAnswerIsNotFetched(): void
    {
        $this->config->method('isAdvisoryLookupEnabled')->willReturn(true);
        $this->cache->method('load')->willReturn('{"data":{"v":1},"fetched_at":"2026-10-13 10:00:00 UTC"}');
        $this->fetcher->expects($this->never())->method('fetch');

        $result = $this->remote()->get('https://magento.watch/api/v1/x');

        $this->assertSame(['v' => 1], $result['data']);
        $this->assertTrue($result['cached']);
    }

    public function testAnUnreachableHostIsGivenUpOnButAnHttpErrorIsNot(): void
    {
        $this->config->method('isAdvisoryLookupEnabled')->willReturn(true);
        $this->fetcher->expects($this->exactly(3))->method('fetch')->willReturn(null);
        $this->fetcher->method('getLastError')->willReturnOnConsecutiveCalls(
            'HTTP 404 from https://repo.packagist.org/p2/private/one.json',
            'the endpoint answered with JSON that is not an object or a list',
            'Operation timed out after 10001 milliseconds'
        );

        $remote = $this->remote();
        $remote->get('https://repo.packagist.org/p2/private/one.json');
        $remote->get('https://repo.packagist.org/p2/private/two.json');
        $remote->get('https://repo.packagist.org/p2/a/b.json');

        $this->assertNull($remote->get('https://repo.packagist.org/p2/c/d.json'));
        $this->assertStringContainsString('did not answer earlier', $remote->getLastError());
    }

    public function testOneDocumentReducedTwoWaysIsDownloadedOnce(): void
    {
        $this->config->method('isAdvisoryLookupEnabled')->willReturn(true);
        $this->fetcher->expects($this->once())->method('fetch')->willReturn(['a' => 1, 'b' => 2]);

        $remote = $this->remote();
        $first = $remote->get('https://mirror.mage-os.org/p2/x/y.json', static fn (array $body): int => $body['a'], 'a');
        $second = $remote->get('https://mirror.mage-os.org/p2/x/y.json', static fn (array $body): int => $body['b'], 'b');

        $this->assertSame([1, 2], [$first['data'], $second['data']]);
    }

    private function remote(): RemoteJson
    {
        return new RemoteJson($this->fetcher, $this->cache, new Json(), $this->config);
    }
}
