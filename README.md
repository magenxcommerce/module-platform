# Magenx_Platform

A platform status dashboard inside the Magento admin.

The MagenX stack runs Magento headless behind a private Docker network: MariaDB, Redis,
RabbitMQ, OpenSearch, Nginx and PHP-FPM or FrankenPHP all sit where nobody can see them. Grafana covers
the storefront's request metrics, but there is nothing in the admin that answers *"is the
stack healthy right now?"* — the question a shop admin actually asks when orders stop
confirming or search goes empty.

This module answers it. **System > Tools > Platform Overview** lists the backends down the
left-hand side in the admin's own `admin__page-nav` strip — the same one system
configuration uses — and shows one panel per backend, each with the handful of numbers
that predict trouble, read live from the running service.

Two more tabs look at the installation rather than a backend: **Security** — the Adobe
bulletins open against this release, whether the isolated patches that close them are
recorded as applied, and known advisories against `composer.lock` — and **Compatibility**
— whether the services and extensions this store runs fit the release it would upgrade to.

It is read-only. It writes nothing, changes nothing, runs no cron, and touches no
storefront request path. Opening the page is the only thing that makes it do any work.
Those two tabs are also the only part that reaches outside the private network; see
[Security and Compatibility](#security-and-compatibility) for exactly what they send.

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
| **MariaDB** | `ResourceConnection`, `SHOW GLOBAL STATUS` / `VARIABLES`, one `information_schema` pass, `performance_schema` statement digests | Threads connected against `max_connections`; InnoDB buffer pool hit rate; connections refused because the server was full; slow queries; the query cache — off, absent, or on with its memory, hit rate, prunes and fragmentation; schema size and the five largest tables; the top five statements by call count and by total time |
| **Redis** | `\Credis_Client` against each configured instance (default cache, page cache, sessions) | Memory against `maxmemory`, eviction policy, evicted keys, hit rate, key count, last background save |
| **RabbitMQ** | HTTP management API | Node alarms, memory and disk headroom, and per-queue depth against consumer count |
| **OpenSearch** | HTTP, engine derived from `catalog/search/engine` | Cluster colour, unassigned shards, JVM heap with committed size and the young/old generation pools, old-generation GC counters, node disk, the store's own indices with doc counts, and which credentials the search configuration resolved to |
| **PHP / FPM** | `opcache_get_status()` and friends in-process, plus the php-fpm status page | Every block OPcache publishes — memory and free memory, wasted memory against the percentage it restarts at, the `cache_full` flag, the interned string buffer, cached scripts and cached keys, hit rate with the counters behind it, blacklist misses, the three restart causes counted separately, a restart in flight, when the cache started and last emptied, JIT and its buffer, and preloading — missing required extensions and missing recommended ones (`redis`, `igbinary`), every field the FPM status page publishes — pool, process manager, start time and uptime, accepted connections with their average rate, the listen queue live and at its high-water mark against the socket backlog, idle/active/total and peak-active processes, `max children reached`, slow requests and memory peak — plus the hardening reads: the user FPM runs as, every path it can write measured against the `tmp/` + `var/` + `pub/media/` allowlist, which of `auth.json`, `.git`, `.github`, `deploy*` and the PHP user's home dotfiles it can read, whether process-spawning functions are disabled, and whether cron is reachable — plus host load and disk |
| **Nginx** | `stub_status` | Active connections, dropped connections, requests per connection, worker read/write/wait state. The endpoint URL rides in the tab's summary line rather than a card of its own |
| **imgproxy** | Prometheus `/metrics` | Error rate and errors split by type, 5xx share of requests, worker utilization, the queue/downloading/processing spans — which separate a saturated imgproxy from a slow origin from an expensive image — and libvips memory against its peak |
| **Security** | magento.watch, Packagist's advisory API, and composer files read from disk | The release's support window; each open Adobe bulletin with its isolated patch coloured by evidence — verified in code, applied, declared, patch file present, partially or not in code, or no record — and the order they go on in; which patch-record sources could be read; every patch file on disk checked hunk by hunk against the code; locked packages with a known advisory |
| **Compatibility** | magento.watch, the Mage-OS mirror, Packagist metadata, `composer.lock`, and the version rows of the other tabs | For the installed release and each upgrade candidate: PHP against the release's composer constraint and its tested versions, the database, search, Redis and RabbitMQ against their tested versions, and every third-party package whose requirements exclude what the release pins; then direct dependencies with a newer stable release |
| **FrankenPHP** | Caddy `metrics` handler | Regular-thread utilization with worker threads taken out, the request queue waiting for a free PHP thread, per-worker ready/busy/queue/crashes, OPcache restarts by reason (PHP 8.4+), and — with Caddy's `metrics` global option — requests in flight, average request time and 5xx share |
| **Integrations** | `integration`, `oauth_token` and the integration's ACL role, one query | How many days old each integration's live access token is — amber from 90 days, red from 180, because an integration token never expires on its own and its age is how long a leaked copy would have kept working — and whether the integration has every ACL resource or the AI MCP server. Nothing reads the token itself |

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
- **Max listen queue is read against the socket backlog**, not against zero. A backlog
  high-water mark that has reached `listen queue len` means the kernel had nowhere left to
  put the next connection, and nginx reported that as a 502 — a fault raised by a different
  service, with its cause on this tab.

Thresholds are class constants in each collector rather than admin fields, so there is
nothing to tune and nothing to get wrong; grep for `_WARN_` and `_ERROR_` in
`Model/Collector/` to see every one of them.

## Security and Compatibility

Module versions (`104.0.8-p5`) only imply the release, and Adobe's isolated security
patches change files without changing any package version — so nothing else in the admin
can tell a patched 2.4.8-p5 from an unpatched one. These two tabs answer from three kinds of
source, every one read without executing anything: `composer audit` and `magento-patches
status` need process execution, which a hardened store disables for the PHP user, and
should.

**From disk**, through Magento's filesystem: `composer.json` and `composer.lock`,
`vendor/composer/installed.json` (composer-patches 1.x writes `extra.patches_applied`
there), `patches.lock.json` (composer-patches 2.x), the file `extra.patches-file` names, and
`*.patch` / `*.diff` in `patches/` and `m2-hotfixes/` (top level only). A patch record
matches a bulletin when it names the isolated patch (`VULN-39341`, `2026-jul`) or the
bulletin (`APSB26-146`). Each source is reported as `read`, `absent` or `unreadable`; the
last is a hardened store keeping the PHP user out, which must not read as "no patches".

**From Adobe's `vendor/bin/patch-status`** (the Monthly Security Release Versioning Tool):
it downloads the monthly patches into `var/patch_metadata/.patch_diffs/` and their registry
into `var/patch_metadata/.patch_registry_cache.json`, which names each patch's diff and the
CVEs it closes, so those diffs are matched to bulletins by CVE. The diffs are a cache, not a
record of applying: alone they are no evidence. The tool's own verdict needs `patch` run
through `proc_open`, which a hardened store disables even on the CLI, so it is not read; the
code check below answers the same question without it.

**From the code.** A record says a patch was applied; only the files it changes say it still
is. Every patch file that can be read — in `patches/` or `m2-hotfixes/`, in patch-status's
cache, or a local file a composer-patches record names — is parsed as a unified diff and
each hunk is looked for in the file it targets: the after-image there means applied, the
before-image means not. Lines are compared trimmed, so reindenting or CRLF does not count as
unpatched. Adobe's composer layout (`vendor/magento/module-catalog/...`) and git layout
(`app/code/Magento/Catalog/...`, `lib/internal/Magento/Framework/...`) both resolve to
vendor/. Monthly patches build on each other, so before judging an earlier patch the later
ones found applied are taken back out of an in-memory copy of the file, as patch-status does
in a scratch directory. The code outranks every record: **Verified in code** closes the
patch, **Not in code** or **Partially in code** keeps it open even when composer-patches
says applied. A patch with no file on disk and no record is "No record found", never
"missing".

An isolated patch magento.watch lists only for other releases (its `appliesTo`) does not
close a bulletin on this one unless the code shows it applied: the row says which release to
upgrade to first, and the patch is left out of the apply order.

**From public services**, over https only, cached for an hour in this module's cache type:

| Service | Asked for | What is sent |
|---|---|---|
| [magento.watch](https://magento.watch/api) | Open bulletins and isolated patches for this release; system requirements of each supported release | The release string |
| Packagist `/api/security-advisories/` | Advisories for the locked packages — the database `composer audit` reads | Locked package names, in batches of 40 |
| Mage-OS mirror `/p2/magento/product-community-edition.json` | The release metapackage's `require`: its PHP constraint and the version it pins every magento/* module and library to | Nothing beyond the request |
| Packagist `/p2/<package>.json` | The newest stable release of each direct dependency (magento/* from the mirror instead) | One package name per request |

A host that does not answer at all is skipped for the rest of the page load, so a
firewalled Packagist costs one timeout rather than forty. With the lookups switched off —
`magenx_platform/advisories/enabled` — both tabs still report the installed release, the
patch records and the measured versions.

**How the Compatibility tab decides.** PHP is a blocker only when the release's own
metapackage constraint excludes it, because composer will not install the release at all.
Adobe's lists of tested PHP, database, search, cache and queue versions are lists rather
than ranges, so anything outside them is a warning — 2.4.9 lists PHP 8.5 alone but its
metapackage allows 8.3 to 8.5. A third-party package in `composer.lock` whose `require`
excludes what the release pins (`magento/framework 102.0.*` against 103.0.9, `guzzlehttp/guzzle
^6.5` against ^7.5) is a blocker, because composer would refuse the upgrade until it is
updated or removed. Magento's own packages, and libraries the release itself requires, move
with the release and are not checked.

The same two reports are served to AI agents by `magenxcommerce/module-ai-mcp` as the
`security_status` and `upgrade_compatibility` tools.

## Configuration

`Stores > Configuration > Magenx > Platform Overview` — all default scope, since the stack
is one deployment.

| Path | Default | Purpose |
|---|---|---|
| `magenx_platform/general/enabled` | `1` | Master switch. Off means no backend is contacted at all |
| `magenx_platform/general/timeout` | `3` | Connect and read timeout per probe, in seconds |
| `magenx_platform/general/cache_ttl` | `10` | How long a snapshot is reused, so a held-down refresh cannot become a load generator. Security and Compatibility keep theirs at least 300 s (`minimumTtl` in `etc/di.xml`); `0` switches every snapshot off |
| `magenx_platform/general/auto_refresh` | `0` | Browser-side refresh interval; `0` is manual only |
| `magenx_platform/collectors/enabled_collectors` | all but imgproxy | Which tabs to show. Deselect a backend this deployment does not run |
| `magenx_platform/endpoints/nginx_status_url` | `http://nginx/nginx_status` | An nginx location running `stub_status` |
| `magenx_platform/endpoints/fpm_status_url` | `http://nginx/fpm_status` | The php-fpm `pm.status_path` endpoint |
| `magenx_platform/endpoints/rabbitmq_management_url` | *(empty)* | Empty derives `http://<amqp host>:15672` from `env.php` |
| `magenx_platform/endpoints/imgproxy_metrics_url` | `http://imgproxy:4594/metrics` | The imgproxy Prometheus endpoint. Needs `IMGPROXY_PROMETHEUS_BIND` set, on its own port |
| `magenx_platform/endpoints/frankenphp_metrics_url` | `http://frankenphp:2020/metrics` | A Caddy site block running only the `metrics` handler. Not the admin API |
| `magenx_platform/advisories/enabled` | `1` | Let the Security and Compatibility tabs ask the public services above |
| `magenx_platform/advisories/release_api_url` | `https://magento.watch/api/v1` | Release, bulletin and requirement data. No admin field: set with `config:set` or `env.php` |
| `magenx_platform/advisories/packagist_url` | `https://packagist.org` | Advisory database. No admin field |
| `magenx_platform/advisories/packagist_repo_url` | `https://repo.packagist.org` | Package metadata. No admin field |
| `magenx_platform/advisories/magento_repo_url` | `https://mirror.mage-os.org` | A credential-free composer repository carrying magento/* packages. No admin field |

Only these backends need an address. Nginx, PHP-FPM, imgproxy and FrankenPHP publish their
stats over HTTP rather than through a client library, and RabbitMQ reports nothing over AMQP
itself. imgproxy and FrankenPHP cannot be derived at all: unlike the database, Redis, amqp
and search hosts, they appear nowhere in `app/etc/env.php` or `core_config_data`, because
Magento does not know they exist.

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

### Why FrankenPHP is read from a metrics site block and not the admin API

FrankenPHP registers its `frankenphp_*` metrics in Caddy's own Prometheus registry, beside
Caddy's `caddy_http_*` request metrics. Caddy serves that registry in two places: at
`/metrics` on the admin API (`localhost:2019` by default), and from the `metrics` handler in
any site block. The [FrankenPHP docs](https://frankenphp.dev/docs/metrics/) only say the
metrics appear "when Caddy metrics are enabled" — nothing in them needs the admin API.

The admin API is the wrong one to open to the network. It has no read-only mode: whoever can
reach it can `POST /load` and replace the running configuration, and moving it off loopback
also means setting `origins` to get past its Host check. A site block that does nothing but
`metrics` publishes the same numbers and accepts nothing:

```caddyfile
{
    metrics     # adds caddy_http_* request metrics; the tab's HTTP section needs it
    frankenphp
}

:2020 {
    metrics /metrics
}
```

Leave the admin API on its default loopback address (or `admin off`), keep port `2020` on
the private Docker network rather than publishing it to the host — it is unauthenticated —
and point `frankenphp_metrics_url` at `http://frankenphp:2020/metrics`.

The thread gauges need reading with one detail in mind: FrankenPHP counts a running worker
script as a busy thread for as long as it runs, so `frankenphp_busy_threads` on a server
with workers never drops below the worker count. The tab rates **regular** threads — total
minus workers — and leaves the raw figure unrated beside it. Magento itself does not run in
worker mode, so on this stack the two usually agree.

`frankenphp_queue_depth` and `frankenphp_worker_queue_depth` are read by full name rather
than through the suffix match imgproxy needs: a suffix match on `queue_depth` would fold
every worker's queue into the regular-thread one. Requests served by the `metrics` handler
itself are left out of the HTTP section, so the tab's own probe is not counted as traffic.

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
- **OPcache's limits are three separate ceilings, not one.** `opcache.memory_consumption`,
  `opcache.max_accelerated_files` and `opcache.interned_strings_buffer` fill independently,
  and each has its own restart counter — out-of-memory, hash, manual. A Magento install
  usually reaches the interned string buffer first, and it fills silently: nothing errors,
  class and method names simply stop being shared between scripts. All three are measured
  rows on the PHP tab for that reason, and the restart counters are never rolled into one
  number, since a sizing problem and a deploy would then read the same.
- **Wasted OPcache memory is read against `opcache.max_wasted_percentage`,** not against
  zero. Waste is normal on a running cache; what matters is that crossing that percentage
  makes OPcache throw the whole cache away and recompile from cold. The row prints the
  threshold beside the figure so the distance to it is visible.
- **A query cache that is off is the healthy reading.** MariaDB still ships one and MySQL
  removed it in 8.0. Where it exists it puts one global mutex in front of every `SELECT`,
  and any write to a table drops every cached result for that table — so on a Magento
  database, which writes constantly, it costs throughput on every core to serve a cache
  that is being emptied as fast as it fills. The tab reports `query_cache_type=OFF` as a
  healthy state, says "Not available" where the server has no such thing, and warns when it
  is on — `DEMAND` included, since that still takes the mutex.
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
- **Three writable paths is the whole allowlist.** `tmp/`, `var/` and `pub/media/` are
  everything the PHP user needs, in production and in development alike, and the Hardening rows
  grade against exactly that. `generated/` and `pub/static/` get no developer-mode exemption:
  they are build output, produced by `setup:di:compile` and `setup:static-content:deploy` in the
  pipeline and shipped read-only, and a writable one lets a single upload become code that is
  executed or served. Two things decide how to read the rows. The probe is one level deep — the
  root's immediate children, plus `app/etc`, `app/etc/env.php`, `generated/` and `pub/static/` by
  name — because a recursive walk of `vendor/` inside an admin request is not a thing to do; and
  `is_writable()` answers `true` for everything when the pool runs as root, which is why `Runs As`
  is the first row and goes red on uid 0.
- **Write access is not the whole exposure.** Some paths must not be readable by the PHP user
  either, because opening them is already the breach: `auth.json` is the Marketplace and
  repo.magento.com keys, `.git/` is the source and its history along with whatever `.git/config`
  holds, `.github/` describes how the site is deployed and `deploy*` is the deployment, and the
  PHP user's own `.ssh/`, `.composer/`, `.config/`, `.local/`, `.cache/` and `.bash*` hold ssh
  keys, composer tokens and shell history. None of it is read while serving a page. The home
  half is skipped when the PHP user's home lies inside the Magento root, so nothing is reported
  twice, and `deploy*` and `.bash*` are matched as globs because both are families.
- **Cron is a separate foothold from code execution.** A writable `/etc/cron.d`, `/etc/crontab`
  or cron spool entry is an error on its own, whatever `disable_functions` says — writing a file
  spawns no process, and the job it schedules outlives the webshell being found. `crontab(1)`
  being executable is an error only when PHP can also start a process and `cron.allow` /
  `cron.deny` does not already deny this user. Magento's own cron belongs to a separate system
  user or container; the FPM pool should have no way to install a job at all.
- **The endpoint fields are the sensitive surface.** An admin who holds
  `Magenx_Platform::config` can point the four endpoint settings at any `http` or `https`
  URL and make the PHP container issue a GET to it — which is the feature, since the whole
  job is probing services only that container can reach. The exposure is deliberately
  narrow: no response body is ever echoed back. Nginx and imgproxy bodies are parsed and
  only the extracted numbers rendered, RabbitMQ and OpenSearch responses are read field by
  field, non-`http(s)` schemes are refused outright, and redirects are not followed. Scope
  that ACL resource accordingly — it is the boundary, not the endpoint list.

## Caveats

- The Security and Compatibility tabs take seconds on a cold cache: the Mage-OS manifest
  of every release is 1.4 MB, and the outdated check is one request per direct dependency.
  Every answer is then kept for an hour.
- Only the Magento Open Source metapackage is public, so for Adobe Commerce the
  Compatibility tab checks extensions against the modules both editions share. Mage-OS
  release manifests are not checked yet.
- A store already running these tabs with a saved **Show Tabs** selection does not gain
  them on upgrade; tick them in configuration. New installations get both.

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
