<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework;

use Magenx\Platform\Model\Cache\Type\Snapshot;
use Magenx\Platform\Model\Collector\CollectorInterface;
use Magenx\Platform\Model\CollectorRunner;
use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Metric\Result;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A tab whose inputs change on deploy keeps its snapshot longer than one that
 * measures a live backend, and cache_ttl = 0 still switches every snapshot off.
 */
#[CoversClass(CollectorRunner::class)]
class CollectorRunnerTest extends TestCase
{
    /**
     * @return array<string, array{int, string, int|null}>
     */
    public static function lifetimes(): array
    {
        return [
            'a live tab keeps cache_ttl' => [10, 'redis', 10],
            'a deploy-time tab gets its floor' => [10, 'security', 300],
            'a longer cache_ttl wins over the floor' => [900, 'security', 900],
            'cache_ttl 0 switches the floor off too' => [0, 'security', null],
        ];
    }

    #[DataProvider('lifetimes')]
    public function testTheSnapshotLivesAsLongAsItsTabNeeds(int $configured, string $code, ?int $expected): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getCacheTtl')->willReturn($configured);

        $cache = $this->createMock(Snapshot::class);
        $cache->method('load')->willReturn(false);
        if ($expected === null) {
            $cache->expects($this->never())->method('save');
        } else {
            $cache->expects($this->once())->method('save')->with($this->anything(), $this->anything(), [], $expected);
        }

        $result = $this->createMock(Result::class);
        $result->method('toArray')->willReturn(['status' => 'ok', 'summary' => '', 'sections' => []]);
        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('collect')->willReturn($result);
        $collector->method('getLabel')->willReturn('Tab');

        $runner = new CollectorRunner(
            $cache,
            new Json(),
            $config,
            $this->createMock(LoggerInterface::class),
            ['security' => 300, 'compatibility' => 300]
        );

        $this->assertFalse($runner->run($code, $collector)['cached']);
    }
}
