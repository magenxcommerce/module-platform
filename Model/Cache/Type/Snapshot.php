<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * The cache type the collected snapshots live in.
 *
 * A declared type rather than the generic default pool, because the snapshots
 * were already being tagged and nothing could act on the tag: no row in Cache
 * Management, no `cache:clean magenx_platform`, and `cache:flush` as the only
 * way to force a fresh probe. TagScope stamps CACHE_TAG onto every save, so the
 * tag is what makes the type cleanable rather than a label nobody reads.
 *
 * Going through Magento\Framework\App\Cache\Type\FrontendPool also gets the
 * enable/disable switch for free: the pool wraps the frontend in an AccessProxy
 * that consults the cache state, so switching this type off in Cache Management
 * makes every tab probe live.
 */
class Snapshot extends TagScope
{
    /**
     * Must match the type name in etc/cache.xml — this is the identifier
     * `bin/magento cache:clean` and Cache Management address the type by.
     */
    public const TYPE_IDENTIFIER = 'magenx_platform';

    public const CACHE_TAG = 'MAGENX_PLATFORM';

    /**
     * @param FrontendPool $cacheFrontendPool
     */
    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
