# Magenx_Platform

A platform status dashboard inside the Magento admin.

The MagenX stack runs Magento headless behind a private Docker network: MariaDB, Redis,
RabbitMQ, OpenSearch, Nginx and PHP-FPM all sit where nobody can see them. Grafana covers
the storefront's request metrics, but there is nothing in the admin that answers *"is the
stack healthy right now?"* — the question a shop admin actually asks when orders stop
confirming or search goes empty.

This module answers it. **System > Tools > Platform Overview** shows one tab per backend,
each with the handful of numbers that predict trouble, read live from the running service.

It is read-only. It writes nothing, changes nothing, runs no cron, and touches no
storefront request path. Opening the page is the only thing that makes it do any work.

## What it does not ask you to configure

Nothing about hosts, ports or credentials for MariaDB, Redis, RabbitMQ or OpenSearch. Those
already live in `app/etc/env.php` and `core_config_data`, and the collectors read them from
there. Re-entering them in admin config would create a second source of truth that goes
stale silently — the first time someone rotates the Redis password, the dashboard would
start lying rather than reporting.

No credential is ever placed in the rendered HTML, in the JSON the page fetches, or in a
log line.

## The tabs

| Tab | Source | The lines that matter |
|---|---|---|
| **MariaDB** | `ResourceConnection`, `SHOW GLOBAL STATUS` / `VARIABLES`, one `information_schema` pass, `performance_schema` statement digests | Threads connected against `max_connections`; InnoDB buffer pool hit rate; connections refused because the server was full; slow queries; schema size and the five largest tables; the top five statements by call count and by total time |
| **Redis** | `\Credis_Client` against each configured instance (default cache, page cache, sessions) | Memory against `maxmemory`, eviction policy, evicted keys, hit rate, key count, last background save |
| **RabbitMQ** | HTTP management API | Node alarms, memory and disk headroom, and per-queue depth against consumer count |
| **OpenSearch** | HTTP, engine derived from `catalog/search/engine` | Cluster colour, unassigned shards, JVM heap with committed size and the young/old generation pools, old-generation GC counters, node disk, the store's own indices with doc counts, and which credentials the search configuration resolved to |
| **PHP / FPM** | `opcache_get_status()` and friends in-process, plus the php-fpm status page | OPcache memory and cached keys, missing required extensions and missing recommended ones (`redis`, `igbinary`), FPM listen queue, `max children reached`, host load and disk |
| **Nginx** | `stub_status` | Active connections, dropped connections, requests per connection, worker read/write/wait state. The endpoint URL rides in the tab's summary line rather than a card of its own |
| **imgproxy** | Prometheus `/metrics` | Error rate against request count, worker utilization against `IMGPROXY_WORKERS`, and average download vs processing time — which separates a slow origin from a busy imgproxy |

The two statement-digest sections answer the questions a slow database actually raises —
what runs most often, and what burns the most total time, which are usually different
statements. Both are best-effort: `performance_schema` can be off, and the Magento database
user is often not granted `SELECT` on it. Either way the tab says so in a note instead of
going red, since neither is a fault of the stack.

Two readings are worth calling out because they are commonly misread:

- **A backlog with zero consumers** on the RabbitMQ tab is a stopped
  `bin/magento queue:consumers:start`. It is invisible everywhere else in the admin until
  customers notice their orders never confirm.
- **OPcache figures describe one PHP-FPM worker** — the one that answered your request —
  not the pool. The FPM process-manager rows below them are pool-wide. The page says so in
  a footnote for the same reason.

Thresholds are class constants in each collector rather than admin fields, so there is
nothing to tune and nothing to get wrong; grep for `_WARN_` and `_ERROR_` in
`Model/Collector/` to see every one of them.

## Configuration

`Stores > Configuration > Magenx > Platform Overview` — all default scope, since the stack
is one deployment.

| Path | Default | Purpose |
|---|---|---|
| `magenx_platform/general/enabled` | `1` | Master switch. Off means no backend is contacted at all |
| `magenx_platform/general/timeout` | `3` | Connect and read timeout per probe, in seconds |
| `magenx_platform/general/cache_ttl` | `10` | How long a snapshot is reused, so a held-down refresh cannot become a load generator |
| `magenx_platform/general/auto_refresh` | `0` | Browser-side refresh interval; `0` is manual only |
| `magenx_platform/collectors/enabled_collectors` | all six | Which tabs to show. Deselect a backend this deployment does not run |
| `magenx_platform/endpoints/nginx_status_url` | `http://nginx/nginx_status` | An nginx location running `stub_status` |
| `magenx_platform/endpoints/fpm_status_url` | `http://nginx/fpm_status` | The php-fpm `pm.status_path` endpoint |
| `magenx_platform/endpoints/rabbitmq_management_url` | *(empty)* | Empty derives `http://<amqp host>:15672` from `env.php` |
| `magenx_platform/endpoints/imgproxy_metrics_url` | `http://imgproxy:4594/metrics` | The imgproxy Prometheus endpoint. Needs `IMGPROXY_PROMETHEUS_BIND` set, on its own port |

Only these backends need an address. Nginx, PHP-FPM and imgproxy publish their stats over
HTTP rather than through a client library, and RabbitMQ reports nothing over AMQP itself.
imgproxy is the one that cannot be derived at all: unlike the database, Redis, amqp and
search hosts, it appears nowhere in `app/etc/env.php` or `core_config_data`, because Magento
does not know it exists.

### Why imgproxy is read over Prometheus and not OpenTelemetry

imgproxy publishes numbers two ways and only one of them is readable from a PHP module.
`IMGPROXY_OPEN_TELEMETRY_ENABLE_METRICS` **pushes** OTLP to an OpenTelemetry Collector and
exposes no endpoint, so consuming it would mean running a collector and scraping that
instead — a pipeline, not a tab. `IMGPROXY_PROMETHEUS_BIND=:4594` serves plain-text
exposition over HTTP, which parses with no new dependency, the same way `stub_status` does.

That is a **second listener**: it cannot share the port imgproxy serves images on
(`IMGPROXY_BIND`, `4593` on this stack), which is why the default here is the next port up
rather than the address you already have for imgproxy.

Neither costs anything to leave on: the Prometheus counters are in-process atomic
increments serialized only when scraped, and the OTel metrics exporter is a periodic
goroutine. OpenTelemetry **tracing** is the expensive switch — a span per request — and is
unrelated to this tab. Bind the Prometheus listener to the private network; it is
unauthenticated.

Metric names shift between imgproxy versions, and `IMGPROXY_PROMETHEUS_NAMESPACE` prefixes
them all when set. The reader matches on the name's suffix so it works either way, but if a
row is missing, `curl` the endpoint and compare — a metric this module cannot find is
silently skipped rather than guessed at.

If the RabbitMQ tab reports that the management API did not answer, enable it on the broker:

```bash
rabbitmq-plugins enable rabbitmq_management
```

## How a request is served

The page renders a shell and contacts nothing. The browser then fetches each tab separately
and in parallel from `magenx_platform/overview/metrics?collector=<code>`. That is what keeps
one hung backend from holding the whole page open — it costs one slow panel and nothing
else. Any `\Throwable` from a collector is turned into an "unavailable" tab by
`Model/CollectorRunner.php`, so a dead service can never produce a 500 or a blank page.

Both controllers are gated on `Magenx_Platform::platform` and are GET-only; the admin
secret key comes from `getUrl()` in the block.

## Adding a backend

One class implementing `Model\Collector\CollectorInterface`, one line in `etc/di.xml`. The
array key there *is* the collector code: it keys the pool, it is the stored value in the
`enabled_collectors` multiselect, and it is the `?collector=` parameter. The tab strip and
the config multiselect are both generated from the pool, so nothing else needs editing. A
collector that is not part of every deployment can be left out of the `enabled_collectors`
default in `etc/config.xml`, which offers the tab without switching it on — that is how the
imgproxy tab ships.

## Install

```bash
composer require magenxcommerce/module-platform
bin/magento module:enable Magenx_Platform
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Two things that bite when reading these values

- **Extension names are not the names you type.** OPcache registers itself as
  `Zend OPcache`, and `extension_loaded('opcache')` is therefore `false` on a server that
  very much has it. The PHP tab checks every name PHP might have registered, so it no
  longer reports OPcache missing on a healthy box.
- **A search password is usually not encrypted.** Saved through the admin form it goes
  through the `Encrypted` backend model; written straight into `core_config_data` or
  locked into `app/etc/env.php` by deployment tooling — the normal case on this stack —
  it is stored in clear, and `decrypt()` answers an empty string for it. The collector
  decides by shape: only a value matching `<keyVersion>:<cryptVersion>:<payload>` is
  decrypted, so a plaintext password survives even when it contains a colon. A ciphertext
  that will not decrypt is never sent as the password — it would fail authentication
  anyway, and an encrypted Magento secret has no business on the wire.
- **Credentials may live in the host setting.** A docker-compose stack commonly configures
  the search host as `http://user:password@opensearch`. The collector splits those off,
  uses them when no `_username` / `_password` pair is configured, and keeps them out of
  the endpoint URL it renders — a URL row is not a place to publish a password. The tab
  names the resolved user and where it came from, never the password. The same applies to
  the Nginx status URL and the RabbitMQ management URL: both are admin-entered and both are
  rendered through `StatusFetcher::redact()`, so userinfo pasted into either never comes
  back out on the page.

## Caveats

- The dashboard is live-only. There is no history and no sparklines — trends are Grafana's
  job, and `deploy/observability/` already runs it.
- `information_schema.TABLES` is a full scan of the table cache. The schema total and the
  five largest tables are read from a single such scan rather than two, but on a schema
  with many thousands of tables the MariaDB tab may still time out into "unavailable"
  rather than block.
- The search collector derives its config path prefix from `catalog/search/engine`, so it
  works against `opensearch` and `elasticsearch7` alike. An engine of `mysql` reports the
  tab as not applicable rather than broken.
