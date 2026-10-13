<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Framework\Security;

use Magenx\Platform\Model\Collector\CollectorInterface;
use Magenx\Platform\Model\CollectorPool;
use Magenx\Platform\Model\CollectorRunner;
use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Security\EnvironmentVersions;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The database is asked directly; the rest are read from the version rows of
 * the tabs the store has switched on, and a tab that is off is not probed.
 */
#[CoversClass(EnvironmentVersions::class)]
class EnvironmentVersionsTest extends TestCase
{
    public function testItReadsEnabledTabsAndSaysWhichItCouldNot(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->with('SELECT VERSION()')->willReturn('11.4.10-MariaDB-ubu2404');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getEnabledCollectors')->willReturn(['opensearch', 'redis']);

        $pool = $this->createMock(CollectorPool::class);
        $pool->method('get')->willReturn($this->createMock(CollectorInterface::class));

        $runner = $this->createMock(CollectorRunner::class);
        $runner->expects($this->exactly(2))->method('run')->willReturnCallback(
            static fn (string $code): array => ['sections' => [['label' => 'Cluster', 'rows' => [
                ['label' => 'Endpoint', 'value' => 'somewhere'],
                ['label' => 'Version', 'value' => $code === 'opensearch' ? 'Opensearch 2.19.5' : '8.6.1'],
            ]]]]
        );

        $versions = (new EnvironmentVersions($resource, $config, $pool, $runner))->get();
        $measured = array_column($versions['measured'], null, 'component');

        $this->assertSame(PHP_VERSION, $measured['php']['version']);
        $this->assertSame('mariadb', $measured['database']['product']);
        $this->assertSame('opensearch', $measured['search']['product']);
        $this->assertSame('8.6.1', $measured['cache']['version']);
        $this->assertSame(['queue'], array_column($versions['unmeasured'], 'component'));
    }
}
