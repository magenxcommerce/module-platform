<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Collector;

use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Http\JsonFetcher;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\ResultFactory;
use Magenx\Platform\Model\Metric\Status;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Search engine health, addressed with the connection settings the catalog
 * search configuration already carries.
 *
 * The config paths are named after the engine — opensearch_server_hostname,
 * elasticsearch7_server_hostname and so on — so the prefix is derived from
 * catalog/search/engine rather than hardcoded. Hardcoding it is how this kind
 * of collector silently reports "not configured" on a perfectly healthy
 * Elasticsearch install.
 */
class OpenSearch implements CollectorInterface
{
    private const HEAP_WARN_PCT = 85.0;
    private const HEAP_ERROR_PCT = 95.0;

    private const DISK_USED_WARN_PCT = 80.0;
    private const DISK_USED_ERROR_PCT = 90.0;

    private const XML_PATH_ENGINE = 'catalog/search/engine';

    /**
     * Cluster and index health are both reported as a colour, and both roll up
     * the same way, so the mapping lives in one place.
     */
    private const HEALTH_STATUS = [
        'green' => Status::OK,
        'yellow' => Status::WARN,
        'red' => Status::ERROR,
    ];

    private ScopeConfigInterface $scopeConfig;

    private EncryptorInterface $encryptor;

    private JsonFetcher $fetcher;

    private ResultFactory $resultFactory;

    private Formatter $formatter;

    private Status $status;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param JsonFetcher $fetcher
     * @param ResultFactory $resultFactory
     * @param Formatter $formatter
     * @param Status $status
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        JsonFetcher $fetcher,
        ResultFactory $resultFactory,
        Formatter $formatter,
        Status $status
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->fetcher = $fetcher;
        $this->resultFactory = $resultFactory;
        $this->formatter = $formatter;
        $this->status = $status;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'OpenSearch';
    }

    /**
     * @inheritDoc
     */
    public function collect(): Result
    {
        /** @var Result $result */
        $result = $this->resultFactory->create();

        $engine = (string) $this->scopeConfig->getValue(self::XML_PATH_ENGINE);
        if ($engine === '' || $engine === 'mysql') {
            return $result->setStatus(Status::UNAVAILABLE)
                ->setSummary('The catalog search engine is not an OpenSearch or Elasticsearch cluster.');
        }

        $configuredHost = (string) $this->engineConfig($engine, 'server_hostname');
        if ($configuredHost === '') {
            return $result->setStatus(Status::UNAVAILABLE)
                ->setSummary(sprintf('No host is configured for the "%s" search engine.', $engine));
        }

        // A docker-compose stack commonly carries the credentials in the host
        // setting itself — http://user:password@opensearch — so split them off
        // before anything else. $base must never carry them: it is rendered on
        // the tab, and a URL row is not a place to publish a password.
        [$host, $inlineUser, $inlinePassword] = $this->splitUserInfo($configuredHost);

        $port = (int) $this->engineConfig($engine, 'server_port');
        $prefix = (string) $this->engineConfig($engine, 'index_prefix');
        $base = $this->buildBaseUrl($host, $port);

        [$user, $password] = $this->credentials($engine);
        $credentialSource = 'Stores > Configuration > Catalog > Catalog > Catalog Search.';
        if ($user === '' && $inlineUser !== '') {
            [$user, $password] = [$inlineUser, $inlinePassword];
            $credentialSource = 'Taken from the credentials embedded in the configured host name.';
        }

        $result->add('Cluster', 'Configured Engine', $engine);
        $result->add('Cluster', 'Endpoint', $base);
        $result->add('Cluster', 'Index Prefix', $prefix !== '' ? $prefix : 'n/a');
        // Say out loud which credentials were resolved from the search
        // configuration. An auth mismatch otherwise shows up only as a bare
        // 401 with no way to tell whether the module even read the settings.
        $result->add(
            'Cluster',
            'Authentication',
            $user !== '' ? sprintf('Basic, as %s', $user) : 'Disabled',
            Status::INFO,
            $user !== ''
                ? $credentialSource
                : 'No credentials are configured, so none are sent.'
        );

        $root = $this->getJson($base . '/', $user, $password);
        if ($root === null) {
            return $result->setStatus(Status::UNAVAILABLE)
                ->setSummary(sprintf('The search cluster did not answer (%s).', $this->fetcher->getLastError()));
        }

        $distribution = (string) ($root['version']['distribution'] ?? 'elasticsearch');
        $version = (string) ($root['version']['number'] ?? '?');
        $result->setSummary(sprintf('%s %s', ucfirst($distribution), $version));
        $result->add('Cluster', 'Version', sprintf('%s %s', ucfirst($distribution), $version));

        $this->addHealthRows($result, $base, $user, $password);
        $this->addNodeRows($result, $base, $user, $password);
        $this->addIndexRows($result, $base, $prefix, $user, $password);

        return $result;
    }

    /**
     * @param Result $result
     * @param string $base
     * @param string $user
     * @param string $password
     * @return void
     */
    private function addHealthRows(Result $result, string $base, string $user, string $password): void
    {
        $health = $this->getJson($base . '/_cluster/health', $user, $password);
        if ($health === null) {
            return;
        }

        $section = 'Cluster';
        $colour = (string) ($health['status'] ?? 'red');

        $result->add(
            $section,
            'Health',
            $colour,
            self::HEALTH_STATUS[$colour] ?? Status::ERROR,
            'Yellow means replicas are unassigned, which is normal on a single node. Red means primary shards are missing.'
        );
        $result->add($section, 'Nodes', $this->formatter->number($health['number_of_nodes'] ?? 0));
        $result->add($section, 'Active Shards', $this->formatter->number($health['active_shards'] ?? 0));

        $unassigned = (int) ($health['unassigned_shards'] ?? 0);
        $result->add(
            $section,
            'Unassigned Shards',
            $this->formatter->number($unassigned),
            $unassigned > 0 ? Status::WARN : Status::OK
        );

        $pending = (int) ($health['number_of_pending_tasks'] ?? 0);
        $result->add(
            $section,
            'Pending Tasks',
            $this->formatter->number($pending),
            $pending > 0 ? Status::WARN : Status::OK,
            'A queue that does not drain means the master is overloaded.'
        );
    }

    /**
     * @param Result $result
     * @param string $base
     * @param string $user
     * @param string $password
     * @return void
     */
    private function addNodeRows(Result $result, string $base, string $user, string $password): void
    {
        // No "human" or "pretty" on this request. "pretty" is whitespace for a
        // person reading curl output, and "human" answers with the cluster's own
        // preformatted strings ("1.2gb") — which is exactly what Formatter
        // exists to stop, so that a megabyte reads the same here as on the Redis
        // tab. Everything below is read in bytes and formatted in one place.
        $stats = $this->getJson($base . '/_nodes/stats/jvm,os,fs', $user, $password);
        if ($stats === null || !isset($stats['nodes']) || !is_array($stats['nodes'])) {
            return;
        }

        foreach ($stats['nodes'] as $node) {
            if (!is_array($node)) {
                continue;
            }
            $section = 'Node ' . ($node['name'] ?? '?');
            $jvm = is_array($node['jvm'] ?? null) ? $node['jvm'] : [];

            $this->addHeapRows($result, $section, $jvm);
            $this->addGcRows($result, $section, $jvm);
            $this->addDiskRow($result, $section, $node);

            $result->add(
                $section,
                'JVM Uptime',
                $this->formatter->duration((int) (($jvm['uptime_in_millis'] ?? 0) / 1000)),
                Status::INFO,
                'How long this JVM has been up, which is not the same as how long the container has.'
            );

            $load = $node['os']['cpu']['load_average']['1m'] ?? null;
            if ($load !== null) {
                $result->add($section, 'Load (1m)', sprintf('%.2f', (float) $load));
            }
        }
    }

    /**
     * Heap, what the JVM has actually taken from the OS, and the two generations.
     *
     * All of this rides in the same _nodes/stats/jvm response the tab already
     * pays for; only the heap total was being read from it.
     *
     * @param Result $result
     * @param string $section
     * @param array $jvm
     * @return void
     */
    private function addHeapRows(Result $result, string $section, array $jvm): void
    {
        $mem = is_array($jvm['mem'] ?? null) ? $jvm['mem'] : [];
        $heapPct = (float) ($mem['heap_used_percent'] ?? 0);

        $result->add(
            $section,
            'JVM Heap',
            sprintf(
                '%s / %s (%s)',
                $this->formatter->bytes($mem['heap_used_in_bytes'] ?? 0),
                $this->formatter->bytes($mem['heap_max_in_bytes'] ?? 0),
                $this->formatter->percent($heapPct)
            ),
            $this->status->forCeiling($heapPct, self::HEAP_WARN_PCT, self::HEAP_ERROR_PCT),
            'Sustained above 85% means garbage collection is thrashing and search latency will follow.'
        );

        if (isset($mem['heap_committed_in_bytes'])) {
            $result->add(
                $section,
                'Heap Committed',
                $this->formatter->bytes($mem['heap_committed_in_bytes']),
                Status::INFO,
                'What the JVM has actually reserved from the OS. Equal to the maximum means -Xms and -Xmx '
                . 'match, which is the recommended setting — a committed size that keeps moving is the JVM '
                . 'growing and shrinking the heap under load.'
            );
        }

        // Pool names depend on the collector in use, so every one of these is
        // optional: G1, CMS and the serial collectors do not agree on them.
        $this->addPoolRow(
            $result,
            $section,
            'Young Generation',
            $mem['pools']['young'] ?? null,
            'Short-lived objects. Churn here is normal and cheap.'
        );
        $this->addPoolRow(
            $result,
            $section,
            'Old Generation',
            $mem['pools']['old'] ?? null,
            'Objects that survived collection. An old generation that stays near full is what precedes '
            . 'an out-of-memory, long before the heap total looks alarming.'
        );
    }

    /**
     * One memory pool, when the running collector publishes it.
     *
     * @param Result $result
     * @param string $section
     * @param string $label
     * @param mixed $pool
     * @param string $hint
     * @return void
     */
    private function addPoolRow(Result $result, string $section, string $label, $pool, string $hint): void
    {
        if (!is_array($pool) || !isset($pool['used_in_bytes'])) {
            return;
        }

        $used = (float) $pool['used_in_bytes'];
        $max = (float) ($pool['max_in_bytes'] ?? 0);

        // G1 reports no maximum for its regions, so show the used figure alone
        // rather than dividing by zero into a meaningless percentage.
        $result->add(
            $section,
            $label,
            $max > 0 ? $this->formatter->bytesOf($used, $max) : $this->formatter->bytes($used),
            Status::INFO,
            $hint
        );
    }

    /**
     * Garbage collection counters.
     *
     * @param Result $result
     * @param string $section
     * @param array $jvm
     * @return void
     */
    private function addGcRows(Result $result, string $section, array $jvm): void
    {
        $collectors = $jvm['gc']['collectors'] ?? null;
        if (!is_array($collectors)) {
            return;
        }

        $this->addGcRow(
            $result,
            $section,
            'GC Young',
            $collectors['young'] ?? null,
            'Young collections run constantly and are cheap. This number is context for the one below it.'
        );
        $this->addGcRow(
            $result,
            $section,
            'GC Old',
            $collectors['old'] ?? null,
            'The counter that actually predicts heap trouble: old-generation collections should be rare. '
            . 'A count that climbs while you watch means the heap is undersized for this index.'
        );

        $threads = $jvm['threads'] ?? null;
        if (is_array($threads) && isset($threads['count'])) {
            $result->add(
                $section,
                'Threads',
                sprintf(
                    '%s (peak %s)',
                    $this->formatter->number($threads['count']),
                    $this->formatter->number($threads['peak_count'] ?? $threads['count'])
                )
            );
        }
    }

    /**
     * One garbage collector's count and accumulated time.
     *
     * @param Result $result
     * @param string $section
     * @param string $label
     * @param mixed $collector
     * @param string $hint
     * @return void
     */
    private function addGcRow(Result $result, string $section, string $label, $collector, string $hint): void
    {
        if (!is_array($collector) || !isset($collector['collection_count'])) {
            return;
        }

        $result->add(
            $section,
            $label,
            sprintf(
                '%s collections, %s total',
                $this->formatter->number($collector['collection_count']),
                $this->formatter->seconds((float) ($collector['collection_time_in_millis'] ?? 0) / 1000)
            ),
            Status::INFO,
            $hint
        );
    }

    /**
     * @param Result $result
     * @param string $section
     * @param array $node
     * @return void
     */
    private function addDiskRow(Result $result, string $section, array $node): void
    {
        $total = (float) ($node['fs']['total']['total_in_bytes'] ?? 0);
        $available = (float) ($node['fs']['total']['available_in_bytes'] ?? 0);
        if ($total <= 0) {
            return;
        }

        $usedPct = $this->formatter->ratio($total - $available, $total);
        $result->add(
            $section,
            'Disk',
            sprintf('%s free of %s', $this->formatter->bytes($available), $this->formatter->bytes($total)),
            $this->status->forCeiling($usedPct, self::DISK_USED_WARN_PCT, self::DISK_USED_ERROR_PCT),
            'At 85% used the cluster stops allocating shards to this node; at 95% it turns indices read-only.'
        );
    }

    /**
     * @param Result $result
     * @param string $base
     * @param string $prefix
     * @param string $user
     * @param string $password
     * @return void
     */
    private function addIndexRows(Result $result, string $base, string $prefix, string $user, string $password): void
    {
        // Scoped to this store's own index prefix: a shared cluster can hold
        // hundreds of indices that have nothing to do with this Magento.
        $pattern = $prefix !== '' ? rawurlencode($prefix) . '*' : '*';
        $url = $base . '/_cat/indices/' . $pattern
            . '?format=json&bytes=b&h=health,status,index,docs.count,store.size&s=index:asc';

        $indices = $this->getJson($url, $user, $password);
        if (!is_array($indices)) {
            return;
        }

        $section = 'Indices';
        if ($indices === []) {
            $result->add(
                $section,
                'Indices',
                'None',
                Status::WARN,
                'No index matches the configured prefix — the catalogsearch_fulltext indexer has never run against this cluster.'
            );

            return;
        }

        foreach ($indices as $index) {
            if (!is_array($index)) {
                continue;
            }
            $health = (string) ($index['health'] ?? 'red');
            $result->add(
                $section,
                (string) ($index['index'] ?? '?'),
                sprintf(
                    '%s docs, %s',
                    $this->formatter->number($index['docs.count'] ?? 0),
                    $this->formatter->bytes($index['store.size'] ?? 0)
                ),
                self::HEALTH_STATUS[$health] ?? Status::ERROR,
                sprintf('Health %s, status %s.', $health, $index['status'] ?? '?')
            );
        }
    }

    /**
     * @param string $engine
     * @param string $suffix
     * @return string
     */
    private function engineConfig(string $engine, string $suffix): string
    {
        return (string) $this->scopeConfig->getValue(sprintf('catalog/search/%s_%s', $engine, $suffix));
    }

    /**
     * Search credentials, decrypted only long enough to make the request.
     *
     * @param string $engine
     * @return string[]
     */
    private function credentials(string $engine): array
    {
        if (!$this->scopeConfig->isSetFlag(sprintf('catalog/search/%s_enable_auth', $engine))) {
            return ['', ''];
        }

        return [
            $this->engineConfig($engine, 'username'),
            $this->readSecret($this->engineConfig($engine, 'password')),
        ];
    }

    /**
     * Read a config value that is normally encrypted, but is not always.
     *
     * A password saved through the admin form goes through the Encrypted
     * backend model and must be decrypted. The same path locked into
     * app/etc/env.php by deployment tooling is stored in clear, and
     * decrypt() answers an empty string for it — which authenticates as
     * nobody and looks exactly like the module ignoring the settings. So the
     * shape of the value, not the result of decrypting it, decides which of
     * the two it is.
     *
     * @param string $value
     * @return string
     */
    private function readSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // A Magento ciphertext is "<keyVersion>:<cryptVersion>:<payload>".
        // Anything else is stored in clear — which is the normal case here,
        // where the value is written straight into core_config_data or locked
        // into app/etc/env.php by deployment tooling — and handing it to
        // decrypt() returns an empty string, i.e. authenticating as nobody.
        // Testing the shape rather than the result also keeps a plaintext
        // password that happens to contain a colon intact.
        if (preg_match('/^\d+:\d+:/', $value) !== 1) {
            return $value;
        }

        try {
            $decrypted = $this->encryptor->decrypt($value);
        } catch (\Throwable) {
            $decrypted = '';
        }

        // A ciphertext that would not decrypt is NOT sent as the password. It
        // would fail authentication anyway, and putting an encrypted Magento
        // secret on the wire — and into the search cluster's auth log — is
        // strictly worse than sending nothing. The plaintext case never reaches
        // here: it is answered by the shape test above.
        return $decrypted;
    }

    /**
     * Split "http://user:password@host" into the host and its credentials.
     *
     * @param string $host
     * @return string[] [host without credentials, user, password]
     */
    private function splitUserInfo(string $host): array
    {
        if (preg_match('#^(https?://)?([^/@]*)@(.+)$#i', $host, $matches) !== 1) {
            return [$host, '', ''];
        }

        $credentials = explode(':', $matches[2], 2);

        return [$matches[1] . $matches[3], $credentials[0], $credentials[1] ?? ''];
    }

    /**
     * @param string $host
     * @param int $port
     * @return string
     */
    private function buildBaseUrl(string $host, int $port): string
    {
        // There is no scheme field in the search configuration: Magento's own
        // client takes the scheme from the hostname when the admin typed one
        // there, and defaults to http otherwise. Match that exactly, or this
        // tab reports a cluster unreachable that Magento talks to happily.
        $scheme = 'http';
        if (preg_match('#^(https?)://#i', $host, $matches) === 1) {
            $scheme = strtolower($matches[1]);
        } elseif (in_array($port, [443, 9243], true)) {
            // No scheme given, but a port that is only ever TLS in practice.
            $scheme = 'https';
        }

        $host = rtrim((string) preg_replace('#^https?://#i', '', $host), '/');

        // A port already written into the hostname wins over the port field,
        // which is what Magento's own string concatenation ends up doing.
        if (preg_match('#:\d+$#', $host) === 1 || $port <= 0) {
            return sprintf('%s://%s', $scheme, $host);
        }

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    /**
     * @param string $url
     * @param string $user
     * @param string $password
     * @return array|null
     */
    private function getJson(string $url, string $user, string $password): ?array
    {
        return $this->fetcher->fetch($url, $user !== '' ? $user : null, $password);
    }
}
