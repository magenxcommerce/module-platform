<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Collector;

use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Http\StatusFetcher;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\ResultFactory;
use Magenx\Platform\Model\Metric\Status;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The PHP tier: what this worker can see about itself, plus the pool-wide view
 * from the php-fpm status page.
 *
 * The split matters and the template says so out loud. OPcache numbers come
 * from opcache_get_status() inside the one FPM worker that happened to answer
 * this request, so they describe that process and not the pool. The process
 * manager numbers below them are pool-wide. Reading the first set as if it were
 * the second is the standard way to misdiagnose an OPcache problem.
 */
class Php implements CollectorInterface
{
    private const OPCACHE_MEMORY_WARN_PCT = 85.0;
    private const OPCACHE_MEMORY_ERROR_PCT = 95.0;

    private const OPCACHE_KEYS_WARN_PCT = 85.0;
    private const OPCACHE_KEYS_ERROR_PCT = 95.0;

    private const DISK_USED_WARN_PCT = 80.0;
    private const DISK_USED_ERROR_PCT = 90.0;

    /** Extensions this stack actually depends on being present. */
    /**
     * Display name => the names PHP may have registered the extension under.
     *
     * OPcache is why this is a map rather than a flat list: it registers as
     * "Zend OPcache", extension_loaded() matches on the registered name, and
     * extension_loaded('opcache') is therefore false on a server that very
     * much has OPcache. Reporting it missing there is worse than not checking.
     */
    private const REQUIRED_EXTENSIONS = [
        'opcache' => ['Zend OPcache', 'opcache'],
        'pdo_mysql' => ['pdo_mysql'],
        'intl' => ['intl'],
        'sodium' => ['sodium'],
        'curl' => ['curl'],
        'sockets' => ['sockets'],
        'bcmath' => ['bcmath'],
        'gd' => ['gd'],
    ];

    /**
     * Extensions that are not required but change how well this stack runs.
     *
     * Same shape as REQUIRED_EXTENSIONS, reported separately and never as an
     * error. Magento requires neither, and this module's own Redis tab talks to
     * Redis through pure-PHP Credis precisely so it works on a container built
     * without ext-redis — so a missing one is advice, not a fault.
     */
    private const RECOMMENDED_EXTENSIONS = [
        'redis' => ['redis'],
        'igbinary' => ['igbinary'],
    ];

    private StatusFetcher $fetcher;

    private Config $config;

    private Json $json;

    private DirectoryList $directoryList;

    private FileDriver $filesystemDriver;

    private ResultFactory $resultFactory;

    private Formatter $formatter;

    private Status $status;

    /**
     * @param StatusFetcher $fetcher
     * @param Config $config
     * @param Json $json
     * @param DirectoryList $directoryList
     * @param FileDriver $filesystemDriver
     * @param ResultFactory $resultFactory
     * @param Formatter $formatter
     * @param Status $status
     */
    public function __construct(
        StatusFetcher $fetcher,
        Config $config,
        Json $json,
        DirectoryList $directoryList,
        FileDriver $filesystemDriver,
        ResultFactory $resultFactory,
        Formatter $formatter,
        Status $status
    ) {
        $this->fetcher = $fetcher;
        $this->config = $config;
        $this->json = $json;
        $this->directoryList = $directoryList;
        $this->filesystemDriver = $filesystemDriver;
        $this->resultFactory = $resultFactory;
        $this->formatter = $formatter;
        $this->status = $status;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'PHP / FPM';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        /** @var Result $result */
        $result = $this->resultFactory->create();
        $result->setSummary(sprintf('PHP %s (%s)', PHP_VERSION, PHP_SAPI));

        $this->addRuntimeRows($result);
        $this->addExtensionRow($result);
        $this->addOpcacheRows($result);
        $this->addFpmRows($result);
        $this->addHostRows($result);

        return $result;
    }

    /**
     * @param Result $result
     * @return void
     */
    private function addRuntimeRows(Result $result): void
    {
        $section = 'Runtime';

        $result->add($section, 'Version', PHP_VERSION);
        $result->add($section, 'SAPI', PHP_SAPI);
        $result->add($section, 'Memory Limit', (string) ini_get('memory_limit'));
        $result->add($section, 'Max Execution Time', (string) ini_get('max_execution_time') . 's');
        $result->add(
            $section,
            'Peak Memory (this request)',
            $this->formatter->bytes(memory_get_peak_usage(true))
        );
        $result->add(
            $section,
            'Realpath Cache',
            $this->formatter->bytesOf(
                (float) realpath_cache_size(),
                (float) $this->toBytes((string) ini_get('realpath_cache_size'))
            ),
            Status::INFO,
            'A full realpath cache makes Magento stat the same files on every request.'
        );
    }

    /**
     * @param Result $result
     * @return void
     */
    private function addExtensionRow(Result $result): void
    {
        $missingRequired = $this->missingFrom(self::REQUIRED_EXTENSIONS);
        $result->add(
            'Runtime',
            'Required Extensions',
            $missingRequired === [] ? 'All present' : 'Missing: ' . implode(', ', $missingRequired),
            $missingRequired === [] ? Status::OK : Status::ERROR,
            implode(', ', array_keys(self::REQUIRED_EXTENSIONS))
        );

        // Warn, never error: the page must not go red over an extension that
        // Magento does not require and that this module does not need to do its
        // own job.
        $missingRecommended = $this->missingFrom(self::RECOMMENDED_EXTENSIONS);
        $result->add(
            'Runtime',
            'Recommended Extensions',
            $missingRecommended === [] ? 'All present' : 'Missing: ' . implode(', ', $missingRecommended),
            $missingRecommended === [] ? Status::OK : Status::WARN,
            'phpredis moves cache and session traffic faster than the pure-PHP client Magento falls back '
            . 'to, and igbinary shrinks what gets stored in Redis. Neither is required, and the Redis tab '
            . 'reads your instances without them.'
        );
    }

    /**
     * The display names in a map whose every candidate name is unloaded.
     *
     * @param array $extensions Display name => the names PHP may have registered it under.
     * @return string[]
     */
    private function missingFrom(array $extensions): array
    {
        $missing = [];
        foreach ($extensions as $label => $candidates) {
            $loaded = false;
            foreach ($candidates as $candidate) {
                if (extension_loaded($candidate)) {
                    $loaded = true;
                    break;
                }
            }
            if (!$loaded) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * @param Result $result
     * @return void
     */
    private function addOpcacheRows(Result $result): void
    {
        $section = 'OPcache (this worker only)';

        if (!function_exists('opcache_get_status')) {
            $result->add($section, 'Enabled', 'No', Status::ERROR, 'OPcache is not loaded. Magento will be several times slower.');

            return;
        }

        $opcache = opcache_get_status(false);
        if (!is_array($opcache) || empty($opcache['opcache_enabled'])) {
            $result->add($section, 'Enabled', 'No', Status::ERROR, 'OPcache is loaded but disabled for this SAPI.');

            return;
        }

        $memory = $opcache['memory_usage'] ?? [];
        $statistics = $opcache['opcache_statistics'] ?? [];

        $used = (float) ($memory['used_memory'] ?? 0);
        $total = $used + (float) ($memory['free_memory'] ?? 0) + (float) ($memory['wasted_memory'] ?? 0);
        $usedPct = $this->formatter->ratio($used, $total);

        $result->add($section, 'Enabled', 'Yes', Status::OK);
        $result->add(
            $section,
            'Memory',
            $this->formatter->bytesOf($used, $total),
            $this->status->forCeiling($usedPct, self::OPCACHE_MEMORY_WARN_PCT, self::OPCACHE_MEMORY_ERROR_PCT),
            'Raise opcache.memory_consumption before this fills, or the cache starts evicting compiled Magento classes.'
        );
        $result->add(
            $section,
            'Wasted Memory',
            $this->formatter->bytes($memory['wasted_memory'] ?? 0),
            Status::INFO,
            'Reclaimed only by a restart.'
        );

        $keys = (float) ($statistics['num_cached_keys'] ?? 0);
        $maxKeys = (float) ($statistics['max_cached_keys'] ?? 0);
        if ($maxKeys > 0) {
            $keysPct = $this->formatter->ratio($keys, $maxKeys);
            $result->add(
                $section,
                'Cached Keys',
                sprintf('%s / %s (%s)', $this->formatter->number($keys), $this->formatter->number($maxKeys), $this->formatter->percent($keysPct)),
                $this->status->forCeiling($keysPct, self::OPCACHE_KEYS_WARN_PCT, self::OPCACHE_KEYS_ERROR_PCT),
                'Against opcache.max_accelerated_files. Magento needs a high five-figure value.'
            );
        }

        $hits = (float) ($statistics['hits'] ?? 0);
        $misses = (float) ($statistics['misses'] ?? 0);
        if ($hits + $misses > 0) {
            $result->add(
                $section,
                'Hit Rate',
                $this->formatter->percent($this->formatter->ratio($hits, $hits + $misses), 2),
                Status::INFO,
                'Low right after a deploy is expected; low hours later is not.'
            );
        }

        $restarts = (int) ($statistics['oom_restarts'] ?? 0);
        $result->add(
            $section,
            'Out-of-memory Restarts',
            $this->formatter->number($restarts),
            $restarts > 0 ? Status::WARN : Status::OK,
            'Each one throws away every compiled class in this worker.'
        );
    }

    /**
     * @param Result $result
     * @return void
     */
    private function addFpmRows(Result $result): void
    {
        $section = 'FPM Pool';
        $url = $this->config->getFpmStatusUrl();

        if ($url === '') {
            $result->add(
                $section,
                'Status Page',
                'Not configured',
                Status::INFO,
                'Set the PHP-FPM Status URL in Stores > Configuration > Magenx > Platform Overview to see pool-wide numbers.'
            );

            return;
        }

        $body = $this->fetcher->fetch($url . (str_contains($url, '?') ? '&' : '?') . 'json');
        if ($body === null) {
            $result->add($section, 'Status Page', 'Unreachable', Status::WARN, $this->fetcher->getLastError());

            return;
        }

        try {
            $fpm = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            $result->add(
                $section,
                'Status Page',
                'Unreadable',
                Status::WARN,
                'The endpoint answered but not with JSON — check that it is really the php-fpm status path.'
            );

            return;
        }

        if (!is_array($fpm)) {
            return;
        }

        $result->add($section, 'Pool', (string) ($fpm['pool'] ?? 'n/a'));
        $result->add($section, 'Process Manager', (string) ($fpm['process manager'] ?? 'n/a'));
        $result->add(
            $section,
            'Processes',
            sprintf(
                '%s active, %s idle, %s total',
                $this->formatter->number($fpm['active processes'] ?? 0),
                $this->formatter->number($fpm['idle processes'] ?? 0),
                $this->formatter->number($fpm['total processes'] ?? 0)
            )
        );

        $listenQueue = (int) ($fpm['listen queue'] ?? 0);
        $result->add(
            $section,
            'Listen Queue',
            sprintf('%d (max seen %d)', $listenQueue, (int) ($fpm['max listen queue'] ?? 0)),
            $listenQueue > 0 ? Status::WARN : Status::OK,
            'Requests waiting for a free worker. Anything above zero is queueing latency the customer feels.'
        );

        $maxChildren = (int) ($fpm['max children reached'] ?? 0);
        $result->add(
            $section,
            'Max Children Reached',
            $this->formatter->number($maxChildren),
            $maxChildren > 0 ? Status::WARN : Status::OK,
            'The pool ran out of workers. Raise pm.max_children if memory allows.'
        );
        $result->add(
            $section,
            'Slow Requests',
            $this->formatter->number($fpm['slow requests'] ?? 0),
            Status::INFO,
            'Counted only when request_slowlog_timeout is set.'
        );
        $result->add($section, 'Accepted Connections', $this->formatter->number($fpm['accepted conn'] ?? 0));
    }

    /**
     * @param Result $result
     * @return void
     */
    private function addHostRows(Result $result): void
    {
        $section = 'Host';

        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load)) {
                $result->add(
                    $section,
                    'Load Average',
                    sprintf('%.2f, %.2f, %.2f', $load[0] ?? 0, $load[1] ?? 0, $load[2] ?? 0),
                    Status::INFO,
                    'Compare against the container CPU limit, not against 1.'
                );
            }
        }

        $this->addDiskRow($result, $section, 'Magento var/', $this->directoryList->getPath(DirectoryList::VAR_DIR));
        $this->addDiskRow($result, $section, 'Root Filesystem', '/');
    }

    /**
     * @param Result $result
     * @param string $section
     * @param string $label
     * @param string $path
     * @return void
     */
    private function addDiskRow(Result $result, string $section, string $label, string $path): void
    {
        // Check the directory is there first, because disk_free_space() warns on
        // a path that is not and the Magento standard rules out silencing it
        // with @. The check goes through the filesystem driver, which is what
        // the standard wants in place of the plain directory-test function.
        //
        // Typed as the concrete Driver\File, NOT DriverInterface: Magento
        // declares no DI preference for that interface, so asking for it makes
        // the object manager throw "Cannot instantiate interface" the moment
        // this collector is constructed.
        try {
            if (!$this->filesystemDriver->isDirectory($path)) {
                return;
            }
        } catch (FileSystemException $e) {
            return;
        }

        $free = disk_free_space($path);
        $total = disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return;
        }

        $usedPct = $this->formatter->ratio($total - $free, $total);
        $result->add(
            $section,
            $label,
            sprintf('%s free of %s', $this->formatter->bytes($free), $this->formatter->bytes($total)),
            $this->status->forCeiling($usedPct, self::DISK_USED_WARN_PCT, self::DISK_USED_ERROR_PCT),
            $path
        );
    }

    /**
     * Turn a php.ini shorthand size ("4M", "512K") into bytes.
     *
     * @param string $value
     * @return int
     */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $number = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }
}
