<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Controller\Adminhtml\Overview;

use Magenx\Platform\Model\CollectorPool;
use Magenx\Platform\Model\CollectorRunner;
use Magenx\Platform\Model\Config;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * One collector, one request.
 *
 * The page asks for each tab separately and in parallel, which is what keeps a
 * single unresponsive backend from delaying the other five. Read-only and GET,
 * so there is no form key to carry; the ACL check inherited from
 * Magento\Backend\App\Action, plus the secret key already in the URL, is the
 * whole gate.
 */
class Metrics extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magenx_Platform::platform';

    private JsonFactory $resultJsonFactory;

    private CollectorPool $pool;

    private CollectorRunner $runner;

    private Config $config;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param CollectorPool $pool
     * @param CollectorRunner $runner
     * @param Config $config
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CollectorPool $pool,
        CollectorRunner $runner,
        Config $config
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->pool = $pool;
        $this->runner = $runner;
        $this->config = $config;
    }

    /**
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        // A probe is a measurement of right now, so it must never be answered
        // from the browser's cache: a store-and-reuse would make Refresh look
        // like it worked while showing the numbers from the previous click.
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $result->setHeader('Pragma', 'no-cache', true);

        // Not a bare (string) cast: ?collector[]=mariadb hands back an array,
        // and casting one raises "Array to string conversion" — log noise on
        // every crafted request, and Magento's error handler promotes warnings
        // to exceptions in developer mode, so it costs a 500 there. All for a
        // parameter that the pool lookup below is about to reject anyway.
        $code = $this->getRequest()->getParam('collector', '');
        $code = is_string($code) ? $code : '';

        if (!$this->config->isEnabled()) {
            return $result->setData(
                $this->runner->unavailable('Platform Overview is switched off in configuration.')
            );
        }

        $collector = $this->pool->get($code);
        if ($collector === null || !in_array($code, $this->config->getEnabledCollectors(), true)) {
            // Deliberately says nothing about the code that was asked for. The
            // page never reads it back — it knows which panel it requested —
            // and echoing an unvalidated request parameter into a response body
            // buys nothing to pay for.
            return $result->setData($this->runner->unavailable('That tab is not enabled.'));
        }

        return $result->setData($this->runner->run($code, $collector));
    }
}
