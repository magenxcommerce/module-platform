<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed reader for magenx_platform/*. Nothing else in the module touches
 * ScopeConfigInterface for these paths.
 *
 * Every value is default scope: the stack is one deployment, not one per store.
 */
class Config
{
    public const XML_PATH_ENABLED = 'magenx_platform/general/enabled';
    public const XML_PATH_TIMEOUT = 'magenx_platform/general/timeout';
    public const XML_PATH_CACHE_TTL = 'magenx_platform/general/cache_ttl';
    public const XML_PATH_AUTO_REFRESH = 'magenx_platform/general/auto_refresh';
    public const XML_PATH_COLLECTORS = 'magenx_platform/collectors/enabled_collectors';
    public const XML_PATH_NGINX_STATUS_URL = 'magenx_platform/endpoints/nginx_status_url';
    public const XML_PATH_FPM_STATUS_URL = 'magenx_platform/endpoints/fpm_status_url';
    public const XML_PATH_RABBITMQ_MANAGEMENT_URL = 'magenx_platform/endpoints/rabbitmq_management_url';
    public const XML_PATH_IMGPROXY_METRICS_URL = 'magenx_platform/endpoints/imgproxy_metrics_url';
    public const XML_PATH_FRANKENPHP_METRICS_URL = 'magenx_platform/endpoints/frankenphp_metrics_url';
    public const XML_PATH_ADVISORIES_ENABLED = 'magenx_platform/advisories/enabled';
    public const XML_PATH_ADVISORIES_RELEASE_API_URL = 'magenx_platform/advisories/release_api_url';
    public const XML_PATH_ADVISORIES_PACKAGIST_URL = 'magenx_platform/advisories/packagist_url';
    public const XML_PATH_ADVISORIES_PACKAGIST_REPO_URL = 'magenx_platform/advisories/packagist_repo_url';
    public const XML_PATH_ADVISORIES_MAGENTO_REPO_URL = 'magenx_platform/advisories/magento_repo_url';

    /**
     * A probe that outlives this is reported as unavailable rather than allowed
     * to hold the admin request open.
     */
    private const MAX_TIMEOUT = 30;

    private ScopeConfigInterface $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * @return int
     */
    public function getTimeout(): int
    {
        $timeout = (int) $this->scopeConfig->getValue(self::XML_PATH_TIMEOUT);

        return max(1, min($timeout ?: 3, self::MAX_TIMEOUT));
    }

    /**
     * @return int
     */
    public function getCacheTtl(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_PATH_CACHE_TTL));
    }

    /**
     * @return int
     */
    public function getAutoRefresh(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_PATH_AUTO_REFRESH));
    }

    /**
     * Collector codes the admin has left switched on.
     *
     * @return string[]
     */
    public function getEnabledCollectors(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_COLLECTORS);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * @return string
     */
    public function getNginxStatusUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_NGINX_STATUS_URL));
    }

    /**
     * @return string
     */
    public function getFpmStatusUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_FPM_STATUS_URL));
    }

    /**
     * Empty means "derive it from the amqp host in app/etc/env.php".
     *
     * @return string
     */
    public function getRabbitMqManagementUrl(): string
    {
        return rtrim(trim((string) $this->scopeConfig->getValue(self::XML_PATH_RABBITMQ_MANAGEMENT_URL)), '/');
    }

    /**
     * Unlike the database, Redis, amqp and search hosts, imgproxy appears
     * nowhere in Magento's own configuration — Magento does not know it exists —
     * so there is nothing to derive and this address has to be given.
     *
     * @return string
     */
    public function getImgProxyMetricsUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_IMGPROXY_METRICS_URL));
    }

    /**
     * A Caddy site block running the metrics handler, not the admin API: the
     * admin API publishes the same registry but also accepts configuration.
     *
     * @return string
     */
    public function getFrankenPhpMetricsUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_FRANKENPHP_METRICS_URL));
    }

    /**
     * Whether the Security and Compatibility tabs may ask public services about
     * this store's release and locked packages. Off, they report only what
     * the installation itself records.
     *
     * @return bool
     */
    public function isAdvisoryLookupEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ADVISORIES_ENABLED);
    }

    /**
     * The magento.watch version API: releases, open bulletins and system
     * requirements.
     *
     * The four advisory URLs have no admin field on purpose. They are
     * addresses the server fetches from, and belong in config.xml, env.php or
     * `config:set` rather than in a form any configuration editor can point at
     * an internal host.
     *
     * @return string
     */
    public function getReleaseApiUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_ADVISORIES_RELEASE_API_URL));
    }

    /**
     * Packagist's API host, which serves the security advisory database.
     *
     * @return string
     */
    public function getPackagistUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_ADVISORIES_PACKAGIST_URL));
    }

    /**
     * Packagist's composer repository, which serves package metadata.
     *
     * @return string
     */
    public function getPackagistRepoUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_ADVISORIES_PACKAGIST_REPO_URL));
    }

    /**
     * A composer repository carrying magento/* packages without credentials —
     * the Mage-OS mirror of repo.magento.com by default.
     *
     * @return string
     */
    public function getMagentoRepoUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_ADVISORIES_MAGENTO_REPO_URL));
    }
}
