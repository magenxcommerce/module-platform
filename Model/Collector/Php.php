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

    private const OPCACHE_INTERNED_WARN_PCT = 85.0;
    private const OPCACHE_INTERNED_ERROR_PCT = 95.0;

    /** PHP's own default for opcache.max_wasted_percentage, used when the directive cannot be read. */
    private const OPCACHE_MAX_WASTED_PCT_DEFAULT = 5.0;

    private const DISK_USED_WARN_PCT = 80.0;
    private const DISK_USED_ERROR_PCT = 90.0;

    /**
     * The root-level tmp/ this stack gives PHP for upload_tmp_dir and friends,
     * which DirectoryList does not name — it knows var/tmp, a different
     * directory. The other two allowlisted paths are read from DirectoryList in
     * probeFilesystem(), which is also where every other DirectoryList code
     * this class uses is named.
     *
     * A DirectoryList constant cannot be used in a constant expression here:
     * the standalone test suite runs without magento/framework on the
     * autoloader, and a class constant referencing one would be resolved when
     * this class is loaded rather than when the probe runs.
     */
    private const WRITABLE_ALLOWLIST_ROOT_DIRS = ['tmp'];

    /**
     * Where a foothold would be dropped to survive the webshell being deleted.
     */
    private const CRON_PATHS = [
        '/etc/crontab',
        '/etc/cron.d',
        '/etc/cron.hourly',
        '/etc/cron.daily',
        '/var/spool/cron',
        '/var/spool/cron/crontabs',
    ];

    /** Where crontab(1) lives on the distributions this stack is built on. */
    private const CRON_BINARIES = ['/usr/bin/crontab', '/bin/crontab', '/usr/local/bin/crontab'];

    /** The functions that let a request start a process at all. */
    private const SPAWN_FUNCTIONS = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open'];

    /**
     * Names in the Magento root the PHP user must not be able to read.
     *
     * Reading is the whole exposure here, with no write needed: auth.json
     * carries the Marketplace and repo.magento.com keys, .git carries the
     * source and its history — credentials in .git/config included — .github
     * describes how the site is deployed, and deploy* is the deployment
     * itself. Nothing in that list is touched while serving a page.
     *
     * Globs, because deploy* is a family: deploy/, deploy.sh, deploy-prod.
     */
    private const UNREADABLE_ROOT_PATTERNS = ['auth.json', '.git', '.github', 'deploy*'];

    /**
     * The same, in the PHP user's home directory where the setup gives it one:
     * ssh keys, composer auth tokens, shell history.
     */
    private const UNREADABLE_HOME_PATTERNS = [
        '.ssh',
        '.composer',
        '.config',
        '.local',
        '.cache',
        '.bash*',
    ];

    /**
     * Extensions this stack depends on being present.
     *
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
     * @var array{user: string, uid: int|null, home: string}|null
     */
    private ?array $effectiveUser = null;

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
        $this->addHardeningRows($result);
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
     * Everything opcache_get_status() publishes about this worker's cache.
     *
     * The call is made once and handed to the builders below, which is what
     * makes them testable: each one takes the decoded array and nothing else.
     *
     * A field that OPcache always publishes is defaulted with ??, because zero
     * really is the measurement on a cache that has just started. A block that
     * only exists on some builds — "jit" arrived in PHP 8.0, and
     * "preload_statistics" is only there when preloading is configured — is
     * gated on its presence instead, so an older or leaner build omits the rows
     * rather than reporting a zero that reads as a measurement.
     *
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

        $result->add($section, 'Enabled', 'Yes', Status::OK);

        $this->addOpcacheMemoryRows($result, $section, $opcache, $this->opcacheMaxWastedPercentage());
        $this->addOpcacheInternedStringRows($result, $section, $opcache);
        $this->addOpcacheScriptRows($result, $section, $opcache);
        $this->addOpcacheRestartRows($result, $section, $opcache);
        $this->addOpcacheJitRows($result, $section, $opcache);
        $this->addOpcachePreloadRows($result, $section, $opcache);
    }

    /**
     * The "memory_usage" block, plus the cache_full flag it explains.
     *
     * Wasted memory is measured against opcache.max_wasted_percentage rather
     * than against zero: some waste is normal on a running cache, and the
     * number only matters as it approaches the point where OPcache throws the
     * whole cache away to reclaim it.
     *
     * @param Result $result
     * @param string $section
     * @param array $opcache
     * @param float $maxWastedPct
     * @return void
     */
    private function addOpcacheMemoryRows(Result $result, string $section, array $opcache, float $maxWastedPct): void
    {
        $memory = is_array($opcache['memory_usage'] ?? null) ? $opcache['memory_usage'] : [];

        $used = (float) ($memory['used_memory'] ?? 0);
        $free = (float) ($memory['free_memory'] ?? 0);
        $wasted = (float) ($memory['wasted_memory'] ?? 0);
        $total = $used + $free + $wasted;
        $usedPct = $this->formatter->ratio($used, $total);

        $result->add(
            $section,
            'Memory',
            $this->formatter->bytesOf($used, $total),
            $this->status->forCeiling($usedPct, self::OPCACHE_MEMORY_WARN_PCT, self::OPCACHE_MEMORY_ERROR_PCT),
            'Raise opcache.memory_consumption before this fills, or the cache starts evicting compiled Magento classes.'
        );
        $result->add(
            $section,
            'Free Memory',
            $this->formatter->bytes($free),
            Status::INFO,
            'What is left for scripts this worker has not compiled yet.'
        );

        // OPcache publishes the percentage itself; it is recomputed only on a
        // build that does not.
        $wastedPct = isset($memory['current_wasted_percentage'])
            ? (float) $memory['current_wasted_percentage']
            : $this->formatter->ratio($wasted, $total);

        $result->add(
            $section,
            'Wasted Memory',
            sprintf(
                '%s (%s, restarts at %s)',
                $this->formatter->bytes($wasted),
                $this->formatter->percent($wastedPct, 2),
                $this->formatter->percent($maxWastedPct, 0)
            ),
            $wastedPct >= $maxWastedPct ? Status::WARN : Status::OK,
            'Memory held by scripts that have since been replaced. It is reclaimed only by a restart, and '
            . 'OPcache forces one the moment it passes opcache.max_wasted_percentage.'
        );

        if (array_key_exists('cache_full', $opcache)) {
            $full = !empty($opcache['cache_full']);
            $result->add(
                $section,
                'Cache Full',
                $full ? 'Yes' : 'No',
                $full ? Status::ERROR : Status::OK,
                'Set when OPcache had nowhere to put a new script — memory or the key table filled. Nothing '
                . 'compiled after that point is cached at all, so those files are recompiled on every request.'
            );
        }
    }

    /**
     * The "interned_strings_usage" block.
     *
     * Its own buffer, sized by its own directive, and the one OPcache limit a
     * Magento install reaches first: every class, method and property name in
     * the generated code is interned once and shared, and the default buffer
     * was not sized for a codebase this shape. It fills silently — the strings
     * simply stop being shared — so it is worth a measured row rather than a
     * reported one.
     *
     * @param Result $result
     * @param string $section
     * @param array $opcache
     * @return void
     */
    private function addOpcacheInternedStringRows(Result $result, string $section, array $opcache): void
    {
        $interned = is_array($opcache['interned_strings_usage'] ?? null) ? $opcache['interned_strings_usage'] : [];
        if ($interned === []) {
            return;
        }

        $buffer = (float) ($interned['buffer_size'] ?? 0);
        if ($buffer > 0) {
            $used = (float) ($interned['used_memory'] ?? 0);
            $usedPct = $this->formatter->ratio($used, $buffer);

            $result->add(
                $section,
                'Interned Strings',
                $this->formatter->bytesOf($used, $buffer),
                $this->status->forCeiling($usedPct, self::OPCACHE_INTERNED_WARN_PCT, self::OPCACHE_INTERNED_ERROR_PCT),
                'Against opcache.interned_strings_buffer. Magento fills the default; a full buffer stops new '
                . 'class and method names being shared between scripts and costs memory in every worker.'
            );
        }

        if (array_key_exists('number_of_strings', $interned)) {
            $result->add(
                $section,
                'Interned String Count',
                $this->formatter->number($interned['number_of_strings']),
                Status::INFO,
                'Distinct strings held in that buffer.'
            );
        }
    }

    /**
     * What the cache holds and how often it answers: "num_cached_scripts",
     * "num_cached_keys" against "max_cached_keys", the hit counters and the
     * blacklist counters.
     *
     * @param Result $result
     * @param string $section
     * @param array $opcache
     * @return void
     */
    private function addOpcacheScriptRows(Result $result, string $section, array $opcache): void
    {
        $statistics = is_array($opcache['opcache_statistics'] ?? null) ? $opcache['opcache_statistics'] : [];

        if (array_key_exists('num_cached_scripts', $statistics)) {
            $result->add(
                $section,
                'Cached Scripts',
                $this->formatter->number($statistics['num_cached_scripts']),
                Status::INFO,
                'PHP files this worker has compiled and kept.'
            );
        }

        $keys = (float) ($statistics['num_cached_keys'] ?? 0);
        $maxKeys = (float) ($statistics['max_cached_keys'] ?? 0);
        if ($maxKeys > 0) {
            $keysPct = $this->formatter->ratio($keys, $maxKeys);
            $result->add(
                $section,
                'Cached Keys',
                sprintf('%s / %s (%s)', $this->formatter->number($keys), $this->formatter->number($maxKeys), $this->formatter->percent($keysPct)),
                $this->status->forCeiling($keysPct, self::OPCACHE_KEYS_WARN_PCT, self::OPCACHE_KEYS_ERROR_PCT),
                'Against opcache.max_accelerated_files. A key is spent on every path a script is reached by, '
                . 'not only on the script, so this sits above the script count. Magento needs a high '
                . 'five-figure value.'
            );
        }

        $hits = (float) ($statistics['hits'] ?? 0);
        $misses = (float) ($statistics['misses'] ?? 0);
        if ($hits + $misses > 0) {
            // OPcache publishes the rate; it is recomputed only on a build that
            // does not.
            $hitRate = isset($statistics['opcache_hit_rate'])
                ? (float) $statistics['opcache_hit_rate']
                : $this->formatter->ratio($hits, $hits + $misses);

            $result->add(
                $section,
                'Hit Rate',
                sprintf(
                    '%s (%s hits, %s misses)',
                    $this->formatter->percent($hitRate, 2),
                    $this->formatter->number($hits),
                    $this->formatter->number($misses)
                ),
                Status::INFO,
                'Low right after a deploy is expected; low hours later is not.'
            );
        }

        if (array_key_exists('blacklist_misses', $statistics)) {
            $result->add(
                $section,
                'Blacklist Misses',
                sprintf(
                    '%s (%s of misses)',
                    $this->formatter->number($statistics['blacklist_misses']),
                    $this->formatter->percent((float) ($statistics['blacklist_miss_ratio'] ?? 0), 2)
                ),
                Status::INFO,
                'Files never cached because they match opcache.blacklist_filename. Zero unless that is set.'
            );
        }
    }

    /**
     * Restarts: the three counters OPcache keeps for them, the state of one in
     * flight, and when this cache was last emptied.
     *
     * The counters are separate because their causes are: an out-of-memory
     * restart says the memory is too small, a hash restart says the key table
     * is, and a manual one says something called opcache_reset(). Rolling them
     * into a single "restarts" number is how a sizing problem gets read as a
     * deploy.
     *
     * @param Result $result
     * @param string $section
     * @param array $opcache
     * @return void
     */
    private function addOpcacheRestartRows(Result $result, string $section, array $opcache): void
    {
        $statistics = is_array($opcache['opcache_statistics'] ?? null) ? $opcache['opcache_statistics'] : [];

        $oomRestarts = (int) ($statistics['oom_restarts'] ?? 0);
        $result->add(
            $section,
            'Out-of-memory Restarts',
            $this->formatter->number($oomRestarts),
            $oomRestarts > 0 ? Status::WARN : Status::OK,
            'Each one throws away every compiled class in this worker. Raise opcache.memory_consumption.'
        );

        $hashRestarts = (int) ($statistics['hash_restarts'] ?? 0);
        $result->add(
            $section,
            'Hash Restarts',
            $this->formatter->number($hashRestarts),
            $hashRestarts > 0 ? Status::WARN : Status::OK,
            'The key table filled before the memory did. Raise opcache.max_accelerated_files.'
        );

        $result->add(
            $section,
            'Manual Restarts',
            $this->formatter->number($statistics['manual_restarts'] ?? 0),
            Status::INFO,
            'opcache_reset() calls. A deploy usually accounts for these.'
        );

        if (array_key_exists('restart_pending', $opcache) || array_key_exists('restart_in_progress', $opcache)) {
            $pending = !empty($opcache['restart_pending']);
            $inProgress = !empty($opcache['restart_in_progress']);

            $result->add(
                $section,
                'Restart',
                $inProgress ? 'In progress' : ($pending ? 'Pending' : 'No'),
                $pending || $inProgress ? Status::WARN : Status::OK,
                'Pending means OPcache is waiting for the requests still holding the old cache to finish. '
                . 'Until the restart completes and the cache warms again, every request is recompiling.'
            );
        }

        $startTime = (int) ($statistics['start_time'] ?? 0);
        if ($startTime > 0) {
            $result->add(
                $section,
                'Cache Started',
                $this->formatUtcTime($startTime, true),
                Status::INFO,
                'When this worker\'s cache was created — which is when the worker started, not when FPM did.'
            );
        }

        if (array_key_exists('last_restart_time', $statistics)) {
            $lastRestart = (int) $statistics['last_restart_time'];
            $result->add(
                $section,
                'Last Restart',
                $lastRestart > 0 ? $this->formatUtcTime($lastRestart, true) : 'Never',
                Status::INFO,
                'The last time the whole cache was emptied, by a reset or by running out of room.'
            );
        }
    }

    /**
     * The "jit" block, on a PHP 8 build that has it.
     *
     * Reported rather than judged. JIT buys little on a Magento request — the
     * time goes to I/O, to the object manager and to string work, not to
     * arithmetic — so neither having it on nor having it off is a fault, and
     * the only number here that can bite is a buffer that has filled.
     *
     * @param Result $result
     * @param string $section
     * @param array $opcache
     * @return void
     */
    private function addOpcacheJitRows(Result $result, string $section, array $opcache): void
    {
        $jit = is_array($opcache['jit'] ?? null) ? $opcache['jit'] : [];
        if ($jit === []) {
            return;
        }

        if (empty($jit['enabled'])) {
            $result->add(
                $section,
                'JIT',
                'Not enabled',
                Status::INFO,
                'opcache.jit_buffer_size is zero or this build has no JIT. Magento does not need it.'
            );

            return;
        }

        $result->add(
            $section,
            'JIT',
            empty($jit['on'])
                ? 'Enabled, not running'
                : sprintf('On (kind %d, opt level %d)', (int) ($jit['kind'] ?? 0), (int) ($jit['opt_level'] ?? 0)),
            Status::INFO,
            'The two digits are the trigger and optimisation level from opcache.jit.'
        );

        $buffer = (float) ($jit['buffer_size'] ?? 0);
        if ($buffer > 0) {
            $free = (float) ($jit['buffer_free'] ?? 0);
            $result->add(
                $section,
                'JIT Buffer',
                $this->formatter->bytesOf($buffer - $free, $buffer),
                Status::INFO,
                'opcache.jit_buffer_size. Once it is full nothing new is compiled; it is not reclaimed '
                . 'without a restart.'
            );
        }
    }

    /**
     * The "preload_statistics" block, present only when opcache.preload is set.
     *
     * @param Result $result
     * @param string $section
     * @param array $opcache
     * @return void
     */
    private function addOpcachePreloadRows(Result $result, string $section, array $opcache): void
    {
        $preload = is_array($opcache['preload_statistics'] ?? null) ? $opcache['preload_statistics'] : [];
        if ($preload === []) {
            return;
        }

        $result->add(
            $section,
            'Preloaded',
            sprintf(
                '%s scripts, %s functions, %s classes',
                $this->formatter->number(is_array($preload['scripts'] ?? null) ? count($preload['scripts']) : 0),
                $this->formatter->number(is_array($preload['functions'] ?? null) ? count($preload['functions']) : 0),
                $this->formatter->number(is_array($preload['classes'] ?? null) ? count($preload['classes']) : 0)
            ),
            Status::INFO,
            'Linked into the parent process at startup and shared by every worker. Changing what is '
            . 'preloaded needs a full FPM restart, not an opcache_reset().'
        );

        if (array_key_exists('memory_consumption', $preload)) {
            $result->add(
                $section,
                'Preload Memory',
                $this->formatter->bytes($preload['memory_consumption']),
                Status::INFO,
                'Taken out of opcache.memory_consumption before the cache above gets any of it.'
            );
        }
    }

    /**
     * opcache.max_wasted_percentage, as a percentage.
     *
     * Read from the directive rather than assumed, because the number is the
     * threshold OPcache will actually restart at. The constant stands in only
     * when the directive cannot be read at all, and carries PHP's own default.
     *
     * @return float
     */
    private function opcacheMaxWastedPercentage(): float
    {
        $configured = ini_get('opcache.max_wasted_percentage');

        return is_numeric($configured) && (float) $configured > 0
            ? (float) $configured
            : self::OPCACHE_MAX_WASTED_PCT_DEFAULT;
    }

    /**
     * A Unix timestamp as UTC, to match every other time on this dashboard
     * rather than whatever zone the host is set to.
     *
     * @param int $timestamp
     * @param bool $withElapsed Append how long ago that was.
     * @return string
     */
    private function formatUtcTime(int $timestamp, bool $withElapsed = false): string
    {
        $formatted = gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
        if (!$withElapsed) {
            return $formatted;
        }

        return $formatted . sprintf(' (%s ago)', $this->formatter->duration(max(0, time() - $timestamp)));
    }

    /**
     * The pool-wide view, one row per field the status page publishes.
     *
     * php-fpm names its fields with spaces ("max children reached"), and those
     * names are the contract — they are what the FPM documentation calls them
     * and what every other tool reading this endpoint keys on.
     *
     * The set of them grows with the PHP version, so which ones are optional
     * decides how they are read. A field that is always published is defaulted
     * with ??, because a pool that has never queued a request really does mean
     * zero. A field that may not exist at all — "memory peak" arrived in PHP
     * 8.1 — is gated on array_key_exists instead, so an older FPM omits the row
     * rather than reporting a zero that reads as a measurement.
     *
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
        } catch (\InvalidArgumentException) {
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
        $result->add(
            $section,
            'Process Manager',
            (string) ($fpm['process manager'] ?? 'n/a'),
            Status::INFO,
            'static keeps every worker resident; dynamic and ondemand start and reap them as traffic moves.'
        );

        $this->addFpmUptimeRow($result, $section, $fpm);
        $this->addFpmProcessRows($result, $section, $fpm);
        $this->addFpmQueueRows($result, $section, $fpm);
        $this->addFpmWorkRows($result, $section, $fpm);
    }

    /**
     * "start time" and "start since" — the same instant said twice.
     *
     * Printed as one row because that is how it gets read: the date answers
     * "was this the deploy?" and the elapsed time answers "how far back?", and
     * neither is worth a row of its own.
     *
     * @param Result $result
     * @param string $section
     * @param array $fpm
     * @return void
     */
    private function addFpmUptimeRow(Result $result, string $section, array $fpm): void
    {
        $startTime = $this->formatFpmStartTime($fpm['start time'] ?? null);
        $since = $fpm['start since'] ?? null;

        if ($startTime === '' && !is_numeric($since)) {
            return;
        }

        $value = $startTime === '' ? 'n/a' : $startTime;
        if (is_numeric($since)) {
            $value .= sprintf(' (up %s)', $this->formatter->duration($since));
        }

        $result->add(
            $section,
            'Started',
            $value,
            Status::INFO,
            'A pool that started more recently than your last deploy was killed and respawned — '
            . 'look for an OOM kill or a crashing worker.'
        );
    }

    /**
     * php-fpm reports "start time" as a Unix timestamp in the JSON format and
     * as an already-formatted date in the HTML one. Both can arrive here, and a
     * timestamp is printed in UTC to match every other time on this dashboard
     * rather than in whatever zone the FPM host happens to be set to.
     *
     * @param mixed $value
     * @return string Empty when the field was absent.
     */
    private function formatFpmStartTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value)) {
            return gmdate('Y-m-d H:i:s', (int) $value) . ' UTC';
        }

        return (string) $value;
    }

    /**
     * "idle processes", "active processes", "total processes" and
     * "max active processes".
     *
     * @param Result $result
     * @param string $section
     * @param array $fpm
     * @return void
     */
    private function addFpmProcessRows(Result $result, string $section, array $fpm): void
    {
        $active = (int) ($fpm['active processes'] ?? 0);
        $idle = (int) ($fpm['idle processes'] ?? 0);
        $total = (int) ($fpm['total processes'] ?? 0);

        $result->add(
            $section,
            'Processes',
            sprintf(
                '%s active, %s idle, %s total',
                $this->formatter->number($active),
                $this->formatter->number($idle),
                $this->formatter->number($total)
            ),
            // No idle worker left means the next request queues behind a
            // running one. It is the moment before "max children reached"
            // starts counting, which is the only saturation warning that
            // arrives early enough to act on.
            //
            // Guarded on more than one worker on purpose: an ondemand pool
            // serving nothing but this very status request reports one process,
            // busy, zero idle, and that is a healthy idle pool rather than a
            // saturated one.
            $total > 1 && $idle === 0 ? Status::WARN : Status::OK,
            'Idle workers are the pool\'s headroom for the next request.'
        );

        if (array_key_exists('max active processes', $fpm)) {
            $result->add(
                $section,
                'Max Active Processes',
                $this->formatter->number($fpm['max active processes']),
                Status::INFO,
                'The busiest this pool has been since it started. Size pm.max_children above it, not at it.'
            );
        }
    }

    /**
     * "listen queue", "max listen queue" and "listen queue len".
     *
     * @param Result $result
     * @param string $section
     * @param array $fpm
     * @return void
     */
    private function addFpmQueueRows(Result $result, string $section, array $fpm): void
    {
        $listenQueue = (int) ($fpm['listen queue'] ?? 0);
        $maxListenQueue = (int) ($fpm['max listen queue'] ?? 0);
        $queueLen = (int) ($fpm['listen queue len'] ?? 0);

        $result->add(
            $section,
            'Listen Queue',
            $this->formatter->number($listenQueue),
            $listenQueue > 0 ? Status::WARN : Status::OK,
            'Requests waiting for a free worker right now. Anything above zero is queueing latency the '
            . 'customer feels.'
        );

        $result->add(
            $section,
            'Max Listen Queue',
            $this->formatter->number($maxListenQueue),
            // Measured against the backlog, not against zero. A queue that has
            // touched its ceiling means the kernel had nowhere left to put the
            // next connection, and nginx saw that as a 502 rather than as a
            // slow page — a different fault, reported by a different service,
            // with its cause on this tab.
            $queueLen > 0 && $maxListenQueue >= $queueLen ? Status::WARN : Status::INFO,
            'The deepest the backlog has been since the pool started.'
        );

        if (array_key_exists('listen queue len', $fpm)) {
            $result->add(
                $section,
                'Listen Queue Length',
                $this->formatter->number($queueLen),
                Status::INFO,
                'The socket backlog (listen.backlog). Connections arriving once the queue is this deep are '
                . 'refused outright.'
            );
        }
    }

    /**
     * What the pool has actually done: "accepted conn", "max children reached",
     * "slow requests" and "memory peak".
     *
     * @param Result $result
     * @param string $section
     * @param array $fpm
     * @return void
     */
    private function addFpmWorkRows(Result $result, string $section, array $fpm): void
    {
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

        $accepted = (float) ($fpm['accepted conn'] ?? 0);
        $since = (float) ($fpm['start since'] ?? 0);
        $result->add(
            $section,
            'Accepted Connections',
            $since > 0
                ? sprintf('%s (%.1f/s)', $this->formatter->number($accepted), $accepted / $since)
                : $this->formatter->number($accepted),
            Status::INFO,
            'Averaged over the whole uptime, not a live rate.'
        );

        if (array_key_exists('memory peak', $fpm)) {
            $result->add(
                $section,
                'Memory Peak',
                $this->formatter->bytes($fpm['memory peak']),
                Status::INFO,
                'The memory usage peak since FPM started. Reported from PHP 8.1 onwards.'
            );
        }
    }

    /**
     * What this install would hand an attacker who reached PHP.
     *
     * Three questions, and none of them is answered by anything else on this
     * dashboard: what the PHP user can write, what it can read that it has no
     * business opening, and whether it can reach cron.
     * A request that gets to run code is a contained incident while it can only
     * write var/, pub/media/ and tmp/; it is a persistent compromise the moment
     * it can write app/etc/, generated/, the document root or a crontab.
     *
     * @param Result $result
     * @return void
     */
    private function addHardeningRows(Result $result): void
    {
        $section = 'Hardening';

        $probe = $this->probeFilesystem();

        $this->buildFilesystemRows($result, $section, $probe);
        $this->buildReadabilityRows($result, $section, $probe);
        $this->buildCronRows($result, $section, $this->probeCron());
    }

    /**
     * Which paths the PHP user can write, split into the allowlist and
     * everything else.
     *
     * The walk is the root's immediate children only — never recursive.
     * vendor/ alone is tens of thousands of entries, and this runs inside an
     * admin page request; the named paths below make up for the depth where it
     * actually matters.
     *
     * @return array
     */
    private function probeFilesystem(): array
    {
        $root = rtrim($this->directoryList->getRoot(), '/');

        // Magento writes uploads and their cached resizes to pub/media, and
        // everything else it produces at runtime — caches, logs, sessions,
        // reports, var/tmp — below var. Both come from DirectoryList so a
        // relocated var/ or a media directory mounted outside the document root
        // is still recognised as itself.
        $allowedPaths = [];
        foreach ([DirectoryList::VAR_DIR, DirectoryList::MEDIA] as $code) {
            $path = $this->pathFor($code);
            if ($path !== '') {
                $allowedPaths[$this->relativeTo($root, $path)] = $path;
            }
        }
        foreach (self::WRITABLE_ALLOWLIST_ROOT_DIRS as $relative) {
            $allowedPaths[$relative] = $root . '/' . $relative;
        }

        // The root itself is a candidate: a writable document root is how a
        // dropped file ends up being served.
        $children = $this->childrenOf($root);
        $candidates = ['.' => $root];
        foreach ($children as $child) {
            $candidates[$this->relativeTo($root, $child)] = $child;
        }
        // Three paths that sit one level below the walk above and are the
        // highest-value targets on the box: app/etc holds env.php and the
        // encryption key, and generated/ and pub/static/ are executed and
        // served respectively.
        foreach ([DirectoryList::CONFIG, DirectoryList::GENERATED, DirectoryList::STATIC_VIEW] as $code) {
            $path = $this->pathFor($code);
            if ($path !== '') {
                $candidates[$this->relativeTo($root, $path)] = $path;
            }
        }
        $config = $this->pathFor(DirectoryList::CONFIG);
        if ($config !== '') {
            $candidates[$this->relativeTo($root, $config . '/env.php')] = $config . '/env.php';
        }

        $allowed = [];
        foreach ($allowedPaths as $relative => $path) {
            unset($candidates[$relative]);
            if ($this->pathExists($path)) {
                $allowed[$this->label($relative, $path)] = $this->isWritablePath($path);
            }
        }

        $other = [];
        foreach ($candidates as $relative => $path) {
            if ($this->pathExists($path)) {
                $other[$this->label($relative, $path)] = $this->isWritablePath($path);
            }
        }
        ksort($other);

        $user = $this->effectiveUser();

        return $user + [
            'allowed' => $allowed,
            'other' => $other,
            'readable' => $this->probeReadability($root, $children, (string) ($user['home'] ?? '')),
        ];
    }

    /**
     * The paths that must not be readable, and whether they are.
     *
     * The root half reuses the walk above rather than repeating it. The home
     * half is walked separately and only when home lies outside the Magento
     * root, so a setup whose PHP user lives in the document root does not have
     * everything reported twice. A home directory that cannot be listed at all
     * yields nothing, which is the healthy reading: the entries this looks for
     * are only findings when they can be opened.
     *
     * @param string $root
     * @param string[] $children Absolute paths of the Magento root's entries.
     * @param string $home
     * @return array<string, bool> Label => readable.
     */
    private function probeReadability(string $root, array $children, string $home): array
    {
        $readable = [];

        foreach ($children as $child) {
            if ($this->matchesAny($this->baseName($child), self::UNREADABLE_ROOT_PATTERNS)) {
                $readable[$this->label($this->relativeTo($root, $child), $child)] = $this->isReadablePath($child);
            }
        }
        ksort($readable);

        $home = rtrim($home, '/');
        if ($home === '' || $home === $root || str_starts_with($home . '/', $root . '/')) {
            return $readable;
        }

        $inHome = [];
        foreach ($this->childrenOf($home) as $entry) {
            if ($this->matchesAny($this->baseName($entry), self::UNREADABLE_HOME_PATTERNS)) {
                // Labelled by the shell's own shorthand, so the row says where
                // the entry is without repeating the home path on each one.
                $inHome[$this->label('~/' . $this->baseName($entry), $entry)] = $this->isReadablePath($entry);
            }
        }
        ksort($inHome);

        return $readable + $inHome;
    }

    /**
     * @param Result $result
     * @param string $section
     * @param array $probe
     * @return void
     */
    private function buildFilesystemRows(Result $result, string $section, array $probe): void
    {
        $uid = $probe['uid'] ?? null;
        $user = (string) ($probe['user'] ?? '');
        $allowed = is_array($probe['allowed'] ?? null) ? $probe['allowed'] : [];
        $other = is_array($probe['other'] ?? null) ? $probe['other'] : [];

        if ($user !== '' || $uid !== null) {
            $isRoot = $uid === 0;
            $result->add(
                $section,
                'Runs As',
                $uid === null ? $user : sprintf('%s (uid %d)', $user === '' ? 'unknown' : $user, $uid),
                $isRoot ? Status::ERROR : Status::INFO,
                $isRoot
                    ? 'This pool runs as root, so every path on the host tests writable and the two rows '
                    . 'below stop measuring anything. Run FPM as an unprivileged user that owns nothing '
                    . 'but the three paths it writes.'
                    : 'The user the two rows below are measured for. Whatever this user can write, a '
                    . 'request that reaches PHP can write.'
            );
        }

        $writable = array_keys(array_filter($allowed));
        $readOnly = array_keys(array_filter($allowed, static function (bool $isWritable): bool {
            return !$isWritable;
        }));

        $result->add(
            $section,
            'Writable Paths',
            $writable === [] ? 'None' : implode(', ', $writable),
            $readOnly === [] && $writable !== [] ? Status::OK : Status::ERROR,
            $readOnly === []
                ? 'tmp/, var/ and pub/media/ are everything the PHP user needs, and everything it should have.'
                : 'Magento cannot run without writing ' . implode(', ', $readOnly) . '.'
        );

        $offenders = array_keys(array_filter($other));
        $result->add(
            $section,
            'Unexpected Writable',
            $offenders === [] ? 'None' : implode(', ', $offenders),
            $offenders === [] ? Status::OK : Status::ERROR,
            'tmp/, var/ and pub/media/ are the whole list, in production and in development alike. '
            . 'Anything else the PHP user can write turns one upload or one template injection into '
            . 'persistent code — generated/ and pub/static/ included, since those are build output that '
            . 'belongs to the deploy user, not to FPM. Checked one level deep, plus app/etc, env.php, '
            . 'generated/ and pub/static/ by name.'
        );
    }

    /**
     * @param Result $result
     * @param string $section
     * @param array $probe
     * @return void
     */
    private function buildReadabilityRows(Result $result, string $section, array $probe): void
    {
        $readable = is_array($probe['readable'] ?? null) ? $probe['readable'] : [];
        $offenders = array_keys(array_filter($readable));

        $result->add(
            $section,
            'Unexpected Readable',
            $offenders === [] ? 'None' : implode(', ', $offenders),
            $offenders === [] ? Status::OK : Status::ERROR,
            'Opening these is already the breach, no write required: auth.json is the Marketplace '
            . 'and repo.magento.com keys, .git/ is the source and its history with whatever '
            . '.git/config holds, .github/ describes the deployment and deploy* is the deployment, '
            . 'and the PHP user\'s .ssh/, .composer/, .config/, .local/, .cache/ and .bash* hold ssh '
            . 'keys, composer tokens and shell history. None of it is read while serving a page.'
        );
    }

    /**
     * Whether cron is reachable from this PHP user, as facts rather than as a
     * verdict — the verdict is built from them below.
     *
     * @return array
     */
    private function probeCron(): array
    {
        $user = (string) ($this->effectiveUser()['user'] ?? '');

        $spawnable = [];
        foreach (self::SPAWN_FUNCTIONS as $function) {
            if (function_exists($function)) {
                $spawnable[] = $function;
            }
        }

        $crontab = '';
        foreach (self::CRON_BINARIES as $candidate) {
            if ($this->isExecutableFile($candidate)) {
                $crontab = $candidate;
                break;
            }
        }

        $paths = self::CRON_PATHS;
        if ($user !== '') {
            // The user's own spool entry, which is writable long before the
            // spool directory is.
            $paths[] = '/var/spool/cron/crontabs/' . $user;
            $paths[] = '/var/spool/cron/' . $user;
        }

        $writable = [];
        foreach ($paths as $path) {
            if ($this->pathExists($path) && $this->isWritablePath($path)) {
                $writable[] = $path;
            }
        }

        return [
            'user' => $user,
            'spawnable' => $spawnable,
            'crontab' => $crontab,
            'denied' => $this->cronDenies($user),
            'writable' => $writable,
        ];
    }

    /**
     * @param Result $result
     * @param string $section
     * @param array $facts
     * @return void
     */
    private function buildCronRows(Result $result, string $section, array $facts): void
    {
        $spawnable = is_array($facts['spawnable'] ?? null) ? $facts['spawnable'] : [];
        $writable = is_array($facts['writable'] ?? null) ? $facts['writable'] : [];
        $crontab = (string) ($facts['crontab'] ?? '');
        $denied = $facts['denied'] ?? null;

        $result->add(
            $section,
            'Process Execution',
            $spawnable === [] ? 'Disabled' : 'Available: ' . implode(', ', $spawnable),
            $spawnable === [] ? Status::OK : Status::WARN,
            'disable_functions is what stops a request shelling out at all. With any of these enabled, '
            . 'every command this user may run is reachable from the web.'
        );

        // A file dropped into a cron directory needs no process of its own, so a
        // writable one is the finding whatever disable_functions says.
        if ($writable !== []) {
            $result->add(
                $section,
                'Cron Access',
                'Writable: ' . implode(', ', $writable),
                Status::ERROR,
                'A file written here runs as whatever user the crontab names, on the schedule it names, '
                . 'and it outlives the webshell being found and deleted. The PHP user must not own or '
                . 'be able to write any of it.'
            );

            return;
        }

        if ($crontab !== '' && $spawnable !== [] && $denied !== true) {
            $result->add(
                $section,
                'Cron Access',
                sprintf('crontab reachable (%s)', $crontab),
                Status::ERROR,
                'This user can execute crontab(1) and PHP can start a process, so a request can install '
                . 'a scheduled job. Deny it in /etc/cron.allow, or drop crontab(1) from the image — '
                . 'Magento\'s own cron belongs to a separate system user or container, never to the '
                . 'FPM pool.'
            );

            return;
        }

        if ($spawnable === []) {
            $reason = 'no process-spawning function is enabled';
        } elseif ($denied === true) {
            $reason = 'denied by cron.allow / cron.deny';
        } else {
            $reason = 'no crontab binary is executable';
        }

        $result->add(
            $section,
            'Cron Access',
            sprintf('No access (%s)', $reason),
            Status::OK,
            'Nothing this request can reach installs a scheduled job. Magento\'s own cron should run '
            . 'from a separate system user or container.'
        );
    }

    /**
     * Whether cron.allow / cron.deny keeps this user out.
     *
     * cron.allow wins where it exists and is exhaustive — a user not listed is
     * denied. cron.deny is only consulted in its absence, and an empty one (the
     * Debian default) denies nobody.
     *
     * @param string $user
     * @return bool|null Null when neither file could be read, so nothing is known.
     */
    private function cronDenies(string $user): ?bool
    {
        if ($user === '') {
            return null;
        }

        $allow = $this->readUserList('/etc/cron.allow');
        if ($allow !== null) {
            return !in_array($user, $allow, true);
        }

        $deny = $this->readUserList('/etc/cron.deny');
        if ($deny !== null) {
            return in_array($user, $deny, true);
        }

        return null;
    }

    /**
     * One user name per line, comments and blanks dropped.
     *
     * @param string $path
     * @return string[]|null Null when the file is absent or unreadable.
     */
    private function readUserList(string $path): ?array
    {
        $contents = $this->contentsOf($path);
        if ($contents === null) {
            return null;
        }

        $users = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $users[] = $line;
            }
        }

        return $users;
    }

    /**
     * The user this process actually runs as.
     *
     * Read from /proc and /etc/passwd rather than from the posix extension:
     * ext-posix is not always built into a PHP image, and the Magento coding
     * standard discourages its functions anyway. Both files are read through
     * the filesystem driver like everything else here, so a container without
     * /proc simply yields no uid and the row that needs one is left out.
     *
     * The home directory comes from the passwd entry and from nowhere else.
     * HOME is not consulted: FPM does not populate it, where it is set it is
     * whatever the last deploy script exported, and the Magento coding standard
     * forbids reading the superglobal that carries it. A uid with no passwd
     * entry therefore has no home here, and the readability probe walks the
     * Magento root alone.
     *
     * Memoized: the filesystem and the cron probes both need it, and the answer
     * cannot change inside one request. Without this the same /proc/self/status
     * and /etc/passwd are read twice — twelve filesystem operations for six
     * operations' worth of answer, since contentsOf() stats each file for
     * existence and readability before opening it.
     *
     * @return array{user: string, uid: int|null, home: string}
     */
    private function effectiveUser(): array
    {
        if ($this->effectiveUser !== null) {
            return $this->effectiveUser;
        }

        $uid = $this->effectiveUid();
        $entry = $uid === null ? [] : $this->passwdEntry($uid);

        return $this->effectiveUser = [
            'user' => (string) ($entry['name'] ?? ''),
            'uid' => $uid,
            'home' => (string) ($entry['home'] ?? ''),
        ];
    }

    /**
     * The effective uid, from the second field of /proc/self/status's Uid line
     * — real, effective, saved, filesystem.
     *
     * @return int|null Null where /proc says nothing, which includes not being Linux.
     */
    private function effectiveUid(): ?int
    {
        $status = $this->contentsOf('/proc/self/status');
        if ($status === null || preg_match('/^Uid:\s+\d+\s+(\d+)/m', $status, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * The name and home directory recorded for a uid in /etc/passwd.
     *
     * @param int $uid
     * @return array{name?: string, home?: string}
     */
    private function passwdEntry(int $uid): array
    {
        $passwd = $this->contentsOf('/etc/passwd');
        if ($passwd === null) {
            return [];
        }

        // name:password:uid:gid:gecos:home:shell
        foreach (preg_split('/\R/', $passwd) ?: [] as $line) {
            $fields = explode(':', $line);
            if (count($fields) >= 6 && $fields[2] !== '' && (int) $fields[2] === $uid) {
                return ['name' => $fields[0], 'home' => rtrim($fields[5], '/')];
            }
        }

        return [];
    }

    /**
     * A file's contents, or null when it cannot be read.
     *
     * @param string $path
     * @return string|null
     */
    private function contentsOf(string $path): ?string
    {
        try {
            if (!$this->filesystemDriver->isExists($path) || !$this->filesystemDriver->isReadable($path)) {
                return null;
            }

            return (string) $this->filesystemDriver->fileGetContents($path);
        } catch (FileSystemException) {
            return null;
        }
    }

    /**
     * The last segment of a path.
     *
     * @param string $path
     * @return string
     */
    private function baseName(string $path): string
    {
        $path = rtrim($path, '/');
        $position = strrpos($path, '/');

        return $position === false ? $path : substr($path, $position + 1);
    }

    /**
     * Whether a file exists and carries an execute bit.
     *
     * Any of the three bits counts. Which one applies depends on the user and
     * the group the pool runs as, and the row this feeds is about the binary
     * being runnable at all rather than about who owns it.
     *
     * @param string $path
     * @return bool
     */
    private function isExecutableFile(string $path): bool
    {
        try {
            if (!$this->filesystemDriver->isExists($path)) {
                return false;
            }

            $stat = $this->filesystemDriver->stat($path);
        } catch (FileSystemException) {
            return false;
        }

        return is_array($stat) && (((int) ($stat['mode'] ?? 0)) & 0111) !== 0;
    }

    /**
     * Whether a name matches any of a set of shell globs.
     *
     * fnmatch() rather than a string comparison because two of the sets are
     * families — deploy/, deploy.sh and deploy-prod are all the deployment, and
     * .bashrc and .bash_history are both the shell's.
     *
     * @param string $name
     * @param string[] $patterns
     * @return bool
     */
    private function matchesAny(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A DirectoryList path, or an empty string for a code this Magento does not
     * know.
     *
     * @param string $code
     * @return string
     */
    private function pathFor(string $code): string
    {
        try {
            return rtrim($this->directoryList->getPath($code), '/');
        } catch (FileSystemException | \InvalidArgumentException) {
            return '';
        }
    }

    /**
     * The immediate children of a directory, or nothing when it cannot be read.
     *
     * @param string $path
     * @return string[]
     */
    private function childrenOf(string $path): array
    {
        try {
            return $this->filesystemDriver->readDirectory($path);
        } catch (FileSystemException) {
            return [];
        }
    }

    /**
     * @param string $path
     * @return bool
     */
    private function pathExists(string $path): bool
    {
        try {
            return $this->filesystemDriver->isExists($path);
        } catch (FileSystemException) {
            return false;
        }
    }

    /**
     * @param string $path
     * @return bool
     */
    private function isWritablePath(string $path): bool
    {
        try {
            return $this->filesystemDriver->isWritable($path);
        } catch (FileSystemException) {
            return false;
        }
    }

    /**
     * @param string $path
     * @return bool
     */
    private function isReadablePath(string $path): bool
    {
        try {
            return $this->filesystemDriver->isReadable($path);
        } catch (FileSystemException) {
            return false;
        }
    }

    /**
     * A path as it reads below the Magento root, or in full when it lives
     * outside it — a media directory mounted elsewhere is worth naming in full.
     *
     * @param string $root
     * @param string $path
     * @return string
     */
    private function relativeTo(string $root, string $path): string
    {
        $path = rtrim($path, '/');

        if ($root !== '' && $path === $root) {
            return '.';
        }

        if ($root !== '' && str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }

    /**
     * How a path is printed in a row: directories keep a trailing slash, files
     * do not, and the Magento root keeps its dot.
     *
     * Decided by asking the filesystem rather than by looking at the name. Two
     * of the three sets here are dotfiles, where the name says nothing — .git
     * is a directory and .bash_history is not, and both would be guessed wrong.
     *
     * @param string $label
     * @param string $path
     * @return string
     */
    private function label(string $label, string $path): string
    {
        if ($label === '.') {
            return $label;
        }

        return $this->isDirectoryPath($path) ? $label . '/' : $label;
    }

    /**
     * @param string $path
     * @return bool
     */
    private function isDirectoryPath(string $path): bool
    {
        try {
            return $this->filesystemDriver->isDirectory($path);
        } catch (FileSystemException) {
            return false;
        }
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

        // pathFor(), like every other DirectoryList read in this class: getPath()
        // throws for a code this Magento does not register, and an uncaught
        // throw here would cost the tab every Runtime, OPcache, FPM and
        // Hardening row already built — CollectorRunner discards the whole
        // Result and renders "unavailable". A missing path is worth one absent
        // row, not the tab.
        $var = $this->pathFor(DirectoryList::VAR_DIR);
        if ($var !== '') {
            $this->addDiskRow($result, $section, 'Magento var/', $var);
        }
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
        } catch (FileSystemException) {
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
