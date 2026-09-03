<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework;

use Magenx\Platform\Model\Http\JsonFetcher;
use Magenx\Platform\Model\Http\StatusFetcher;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Magenx\Platform\Model\Http\JsonFetcher
 */
class JsonFetcherTest extends TestCase
{
    private StatusFetcher&MockObject $statusFetcher;

    private Json&MockObject $json;

    private JsonFetcher $fetcher;

    protected function setUp(): void
    {
        $this->statusFetcher = $this->createMock(StatusFetcher::class);
        $this->json = $this->createMock(Json::class);
        $this->fetcher = new JsonFetcher($this->statusFetcher, $this->json);
    }

    public function testADecodedObjectIsReturned(): void
    {
        $this->statusFetcher->method('fetch')->willReturn('{"status":"green"}');
        $this->json->method('unserialize')->willReturn(['status' => 'green']);

        $this->assertSame(['status' => 'green'], $this->fetcher->fetch('http://opensearch:9200/'));
        $this->assertSame('', $this->fetcher->getLastError());
    }

    public function testCredentialsArePassedStraightThrough(): void
    {
        $this->statusFetcher->expects($this->once())
            ->method('fetch')
            ->with('http://rabbitmq:15672/api/overview', 'guest', 'guest')
            ->willReturn('{}');
        $this->json->method('unserialize')->willReturn([]);

        $this->fetcher->fetch('http://rabbitmq:15672/api/overview', 'guest', 'guest');
    }

    public function testAFailedRequestReportsTheTransportError(): void
    {
        $this->statusFetcher->method('fetch')->willReturn(null);
        $this->statusFetcher->method('getLastError')->willReturn('HTTP 401 from http://rabbitmq:15672');

        $this->assertNull($this->fetcher->fetch('http://rabbitmq:15672/api/overview'));
        $this->assertSame('HTTP 401 from http://rabbitmq:15672', $this->fetcher->getLastError());
    }

    public function testAnEmptyBodyGetsItsOwnReason(): void
    {
        // StatusFetcher records nothing for a 200, so these three cases used to
        // surface as "did not answer ()" with nothing in the brackets.
        $this->statusFetcher->method('fetch')->willReturn('');

        $this->assertNull($this->fetcher->fetch('http://rabbitmq:15672/api/overview'));
        $this->assertSame('the endpoint answered with an empty body', $this->fetcher->getLastError());
    }

    public function testABodyThatIsNotJsonGetsItsOwnReason(): void
    {
        $this->statusFetcher->method('fetch')->willReturn('<html>Sign in</html>');
        $this->json->method('unserialize')->willThrowException(new \InvalidArgumentException('bad json'));

        $this->assertNull($this->fetcher->fetch('http://rabbitmq:15672/api/overview'));
        $this->assertSame('the endpoint answered, but not with JSON', $this->fetcher->getLastError());
    }

    public function testJsonThatIsNotAnArrayGetsItsOwnReason(): void
    {
        $this->statusFetcher->method('fetch')->willReturn('"green"');
        $this->json->method('unserialize')->willReturn('green');

        $this->assertNull($this->fetcher->fetch('http://opensearch:9200/'));
        $this->assertSame(
            'the endpoint answered with JSON that is not an object or a list',
            $this->fetcher->getLastError()
        );
    }

    public function testTheErrorIsClearedBetweenCalls(): void
    {
        $this->statusFetcher->method('fetch')->willReturnOnConsecutiveCalls('', '{}');
        $this->json->method('unserialize')->willReturn([]);

        $this->assertNull($this->fetcher->fetch('http://rabbitmq:15672/'));
        $this->assertNotSame('', $this->fetcher->getLastError());

        $this->assertSame([], $this->fetcher->fetch('http://rabbitmq:15672/'));
        $this->assertSame('', $this->fetcher->getLastError());
    }

    public function testRedactIsProxied(): void
    {
        // So a collector holding this needs no second dependency to render the
        // endpoint it was pointed at.
        $this->statusFetcher->expects($this->once())
            ->method('redact')
            ->with('http://user:pass@rabbitmq:15672')
            ->willReturn('http://rabbitmq:15672');

        $this->assertSame('http://rabbitmq:15672', $this->fetcher->redact('http://user:pass@rabbitmq:15672'));
    }
}
