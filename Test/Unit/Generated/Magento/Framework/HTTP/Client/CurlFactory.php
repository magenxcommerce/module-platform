<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magento\Framework\HTTP\Client;

/**
 * Stand-in for the factory Magento generates into generated/code.
 *
 * magento/framework ships Curl but not CurlFactory: the object manager writes
 * every *Factory at compile time, so a unit run against the bare package has
 * no class for PHPUnit to mock. This mirrors the generated shape — one
 * create() taking the constructor data — and is only ever loaded when no real
 * one is on the autoloader.
 */
class CurlFactory
{
    /**
     * @param array $data
     * @return \Magento\Framework\HTTP\Client\Curl
     */
    public function create(array $data = [])
    {
        return new Curl();
    }
}
