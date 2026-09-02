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
| **MariaDB** | `ResourceConnection`, `SHOW GLOBAL STATUS` / `VARIABLES`, two bounded `information_schema` queries, `performance_schema` statement digests | Threads connected against `max_connections`; InnoDB buffer pool hit rate; connections refused because the server was full; slow queries; schema size and the five largest tables; the top five statements by call count and by total time |
| **Redis** | `\Credis_Client` against each configured instance (default cache, page cache, sessions) | Memory against `maxmemory`, eviction policy, evicted keys, hit rate, key count, last background save |
| **RabbitMQ** | HTTP management API | Node alarms, memory and disk headroom, and per-queue depth against consumer count |
| **OpenSearch** | HTTP, engine derived from `catalog/search/engine` | Cluster colour, unassigned shards, JVM heap, node disk, the store's own indices with doc counts, and which credentials the search configuration resolved to |
| **PHP / FPM** | `opcache_get_status()` and friends in-process, plus the php-fpm status page | OPcache memory and cached keys, missing extensions, FPM listen queue, `max children reached`, host load and disk |
| **Nginx** | `stub_status` | Active connections, dropped connections, requests per connection, worker read/write/wait state. The endpoint URL rides in the tab's summary line rather than a card of its own |

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

Only these three backends need an address: Nginx and PHP-FPM publish their stats over HTTP
rather than through a client library, and RabbitMQ reports nothing over AMQP itself.

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
the config multiselect are both generated from the pool, so nothing else needs editing.

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
- **A search password is not always encrypted.** Saved through the admin form it goes
  through the `Encrypted` backend model; locked into `app/etc/env.php` by deployment
  tooling it is stored in clear, and `decrypt()` answers an empty string for it. The
  OpenSearch collector falls back to the raw value when decryption yields nothing, and
  prints the resolved username on the tab so an auth mismatch is visible rather than
  showing up as a bare 401.

## Caveats

- The dashboard is live-only. There is no history and no sparklines — trends are Grafana's
  job, and `deploy/observability/` already runs it.
- `information_schema.TABLES` is a full scan of the table cache. The largest-tables query is
  capped at five rows, but on a schema with many thousands of tables the MariaDB tab may
  still time out into "unavailable" rather than block.
- The search collector derives its config path prefix from `catalog/search/engine`, so it
  works against `opensearch` and `elasticsearch7` alike. An engine of `mysql` reports the
  tab as not applicable rather than broken.
