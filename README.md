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
| **imgproxy** | Prometheus `/metrics` | Error rate and errors split by type, 5xx share of requests, worker utilization, the queue/downloading/processing spans — which separate a saturated imgproxy from a slow origin from an expensive image — and libvips memory against its peak |

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

Snapshots live in their own cache type, so `cache_ttl` is not the only handle on them:

```bash
bin/magento cache:clean magenx_platform   # force every tab to probe again
```

It also appears in `System > Tools > Cache Management` as **Platform Overview**, and
switching it off there makes every tab probe live regardless of `cache_ttl`.

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

Metric names come from imgproxy's [documented list](https://docs.imgproxy.net/monitoring/prometheus).
Two details from it shape the reader:

- `IMGPROXY_PROMETHEUS_NAMESPACE` prefixes every metric when set, so lookups match on the
  name's **suffix** and work either way without being told which was chosen.
- `errors_total`, `status_codes_total` and `request_span_duration_seconds` are each split by
  a label, and the docs describe those splits ("separated by type", "separated by span")
  without formally naming the labels. The reader therefore keys breakdowns on the label's
  **value**, not its name — these families carry one label each, so that is unambiguous and
  survives a rename. Reading them without labels at all is worse than useless: it reports
  one label set's count as if it were the family total.

A metric this module cannot find skips its row rather than being guessed at, which is what
carries the tab across imgproxy versions. If a row is missing, `curl` the endpoint and
compare. Metrics are served from **any path** on the Prometheus binding, so `/metrics` is a
convention rather than a requirement.

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

A tab that never answers is given up on after 90 seconds browser-side. That deadline is a
backstop rather than a second copy of the probe timeout — the page refuses to start a
second round of probes while one is in flight, so a fetch that never settles would
otherwise leave Refresh and auto-refresh silently doing nothing for as long as the page
stayed open.

Both controllers are gated on `Magenx_Platform::platform` and are GET-only; the admin
secret key comes from `getUrl()` in the block. The metrics response is sent `no-store`, and
the page asks for it with `cache: 'no-store'`, because a probe answered from the browser's
cache would make Refresh look like it worked while showing the previous click's numbers.

The tab strip is a real ARIA tablist: one tab is in the page's tab order and the arrow
keys, `Home` and `End` move between them.

## Adding a backend

One class implementing `Model\Collector\CollectorInterface`, one line in `etc/di.xml`. The
array key there *is* the collector code: it keys the pool, it is the stored value in the
`enabled_collectors` multiselect, and it is the `?collector=` parameter. The tab strip and
the config multiselect are both generated from the pool, so nothing else needs editing. A
collector that is not part of every deployment can be left out of the `enabled_collectors`
default in `etc/config.xml`, which offers the tab without switching it on — that is how the
imgproxy tab ships.

## Compatibility

Targets **Magento 2.4.8 and up**, on **PHP 8.3 or 8.4**.

Magento 2.4.8 itself allows PHP 8.2 as well; this module deliberately does not. PHP 8.2 left
active support in December 2024 and its security window closes at the end of 2026, so the
floor is 8.3. Nothing in the code needs it — there is no PHP 8.3-only syntax anywhere — it is
a support decision, not a technical one. A 2.4.8 site still on 8.2 will not resolve this
package.

Package constraints were checked against the real `magento/magento2` manifests at tag
`2.4.8` rather than from memory: `magento/framework` `103.0.*`, `magento/module-backend`
`102.0.*` (2.4.8 ships 102.0.8), `magento/module-config` `101.2.*`, `magento/module-store`
`101.1.*`, `colinmollenhour/credis` `^1.15`, `phpunit/phpunit` `^10.5`.

The framework behaviours this module leans on were verified against 2.4.8 source, because
several of them are load-bearing enough that the code comments assert them:

| What the module relies on | Why it holds at 2.4.8 |
|---|---|
| The configured probe timeout actually beats curl's own | `Curl::makeRequest()` applies `_curlUserOptions` *after* its built-in `CURLOPT_TIMEOUT`, whose default is 300s — so `setOptions()` wins |
| Basic auth does not linger in curl state | `Curl::setCredentials()` sets an `Authorization` header, not `CURLOPT_USERPWD` |
| `no-store` on the metrics response | `AbstractResult::setHeader($name, $value, $replace = false)` |
| Disabling the cache type makes every tab probe live | `FrontendPool::get()` wraps each type in `AccessProxy`, which returns `false` from `load()` and short-circuits `save()` when disabled |
| Snapshots are tagged without passing a tag | `TagScope::save()` appends its own tag |
| `Magento\Backend\App\Action` is still the right controller base | `@api`, not deprecated in 2.4.8 |

## Install

```bash
composer require magenxcommerce/module-platform
bin/magento module:enable Magenx_Platform
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Tests

```bash
composer install
vendor/bin/phpunit                        # both suites
vendor/bin/phpunit --testsuite standalone # no Magento needed
```

The suite is split because only half of it can run without a Magento install.
`standalone` covers the classes that construct with no framework types at all — the
formatter, the severity vocabulary, the metric rollup, the Prometheus reader — plus the
pure private parsers inside the collectors, reached by reflection because they are total
functions of their arguments and need no test doubles. It runs against nothing but this
module, which matters because `magento/framework` sits behind repo.magento.com
credentials. `framework` covers the classes whose collaborators are Magento interfaces
and needs `composer install` first.

What is pinned there is deliberate: every one of those helpers encodes a decision the
source defends at length — the numeric-string array key in `breakdown()`, the shape test
in `readSecret()`, the scheme and port precedence in `buildBaseUrl()`, `redact()` not
crossing a slash — and a decision defended only by a comment is one careless edit from
silently reverting.

## Things that bite when reading these values

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
- **A configured Redis is not necessarily a Redis in use.** A `session/redis` block
  survives in `app/etc/env.php` after `session/save` is switched away from `redis`, so the
  Sessions card checks `session/save` before probing. When it does not match, the card says
  "In Use: No" and stops there — reporting on an instance Magento never touches would grade
  an idle server's evicted keys as customers being logged out mid-checkout.
- **Credentials may live in the host setting.** A docker-compose stack commonly configures
  the search host as `http://user:password@opensearch`. The collector splits those off,
  uses them when no `_username` / `_password` pair is configured, and keeps them out of
  the endpoint URL it renders — a URL row is not a place to publish a password. The tab
  names the resolved user and where it came from, never the password. The same applies to
  the Nginx status URL and the RabbitMQ management URL: both are admin-entered and both are
  rendered through `StatusFetcher::redact()`, so userinfo pasted into either never comes
  back out on the page.
- **The endpoint fields are the sensitive surface.** An admin who holds
  `Magenx_Platform::config` can point the four endpoint settings at any `http` or `https`
  URL and make the PHP container issue a GET to it — which is the feature, since the whole
  job is probing services only that container can reach. The exposure is deliberately
  narrow: no response body is ever echoed back. Nginx and imgproxy bodies are parsed and
  only the extracted numbers rendered, RabbitMQ and OpenSearch responses are read field by
  field, non-`http(s)` schemes are refused outright, and redirects are not followed. Scope
  that ACL resource accordingly — it is the boundary, not the endpoint list.

## Caveats

- The dashboard is live-only. There is no history and no sparklines — trends are Grafana's
  job, and `deploy/observability/` already runs it.
- `information_schema.TABLES` is a full scan of the table cache. The schema total and the
  five largest tables are read from a single such scan rather than two, and the scan is
  bounded by the backend timeout — so on a schema with many thousands of tables the
  Storage section reports "Not read" and the rest of the tab still fills in. The bound is a
  session variable set around the two slow reads and restored afterwards:
  `max_statement_time` on MariaDB (seconds) or `max_execution_time` on MySQL
  (milliseconds), chosen from whichever the server publishes in `SHOW GLOBAL VARIABLES` —
  which the collector has already read, so it costs no extra round trip. A session variable
  rather than MariaDB's `SET STATEMENT ... FOR` or MySQL's `MAX_EXECUTION_TIME` hint because
  Zend_Db prepares every statement and MariaDB will not prepare a `SET STATEMENT`. It is
  always restored, since Magento reuses the connection for the rest of the request. A server
  that publishes neither variable, or that refuses the `SET`, is read unbounded rather than
  not read at all.
- The search collector derives its config path prefix from `catalog/search/engine`, so it
  works against `opensearch` and `elasticsearch7` alike. An engine of `mysql` reports the
  tab as not applicable rather than broken.
