<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Metric;

/**
 * Stand-in for the ResultFactory Magento generates into generated/code, for
 * the same reason as the CurlFactory beside it: the collectors ask for it, and
 * a unit run has no compiled code to find it in.
 */
class ResultFactory
{
    /**
     * @param array $data
     * @return \Magenx\Platform\Model\Metric\Result
     */
    public function create(array $data = [])
    {
        return new Result(new Status());
    }
}
