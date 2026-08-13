# Register architecture overview

Register is a blog engine built on S2. S2 provides reusable application infrastructure; Register
provides the publishing domain and product experience. The boundary is intentional:

- `S2\Cms` contains HTTP, dependency injection, events, database access, queues, caching, and reusable
  administration infrastructure.
- `Register` contains posts, pages, comments, tags, search, typography, analytics, formula rendering,
  editorial workflows, public presentation, and product policy.

See [ADR 0001](decisions/0001-register-module-tiers.md) for the module-tier decision.

## Application runtime

[`Application`](../_include/src/Framework/Application.php) is the HTTP kernel. Runtime modules provide
container definitions, event listeners, and routes through
[`ModuleInterface`](../_include/src/Framework/ModuleInterface.php). `ExtensionInterface` remains as a
transitional alias for inherited modules.

The boot sequence is:

1. Register the reusable S2 infrastructure module and Register-owned product services.
2. Register the mandatory Register base modules in deterministic order.
3. In control-panel requests, register the base administration module and administration portions of
   the base modules.
4. Discover and register enabled optional modules.
5. Build the container, event dispatcher, and route collection.

The mandatory list is defined in
[`BaseModuleRegistry`](../_include/src/Register/Module/BaseModuleRegistry.php), not in the database.

## Module tiers

### Base modules

Base modules form every Register installation and cannot be disabled or uninstalled:

- Content/Blog and permanent Pages;
- Comments and Tags;
- Search;
- Typography;
- Analytics;
- Math;
- Admin.

All feature modules live under `Register\Module`; `_extensions` is reserved for optional modules.
Some inherited page and administration infrastructure still lives in `S2\Cms` and moves only when a
Register-owned replacement exists. All base schemas use the integer `REGISTER_SCHEMA_REVISION` ledger managed by
[`SchemaMigrator`](../_include/src/Register/Schema/SchemaMigrator.php); manifest versions are only
transitional metadata and are not product migration state.

Built-in Analytics stores daily aggregates in product tables. It retains only salted visitor
fingerprints for the active aggregation day, pruning older fingerprints on subsequent traffic. It
honors DNT and Global Privacy Control and exposes chart data through the authenticated
administration endpoint. Raw IP addresses and User-Agent strings are not stored.

### Optional modules

Optional modules provide integrations and specialized behavior. They can add services, listeners,
routes, administration pages, translations, templates, views, and assets. Their installed and enabled
state may be stored in the database, and they retain independent version and migration metadata.

Optional modules must use public Register services and events. Direct access to product tables is not
a supported integration boundary.

## Search lifecycle

Search consumes the storage-independent [`ContentRepository`](../_include/src/Register/Content/ContentRepository.php)
rather than querying post and page tables itself. Published posts and pages are represented by one
`ContentItem` contract and have typed `post:<id>` and `page:<id>` identities. A fresh installation
synchronously indexes its welcome post and starter pages before reporting success. Product
migrations that change the search identity or storage rebuild the index. Later editorial changes
publish `register_content_index` jobs to the shared queue; the control-panel rebuild remains repair
tooling rather than an installation step.

## Background work lifecycle

Register does not require cron. Every successfully completed web request registers a shutdown
callback, closes the PHP session, sends and detaches the response where the SAPI supports that, and
then offers a small time-limited slice to the durable queue. `ignore_user_abort(true)` is enabled
before the callback is registered, so a client disconnect does not cancel recovery work.

Only one runner may execute at once. A non-blocking lease in the application database serializes
workers across hosts and independent filesystems; it uses the database clock so application-node
clock skew cannot create overlapping ownership. The lease outlives the bounded runner slice and
expires automatically if PHP is killed before it can release ownership.

Queue delivery is at least once. A job remains in the database until a generation-aware
acknowledgement succeeds; failures use exponential backoff and become visible as failed jobs after
the retry limit. Handlers must therefore be idempotent. Republishing the same `(id, code)` advances
its generation, replaces stale payload, and revives failed work. A monotonic execution budget is
shared with the handler and checked before every independently repeatable expensive step. Budget
exhaustion defers the job without consuming a retry attempt. Handler-aware selection skips every
known job that cannot fit the remaining slice, so expensive work cannot starve runnable jobs behind
it. PHP cannot safely interrupt an active extension or database call, so handler inputs and I/O
still need finite server-side limits.

Antispam maintenance uses the same request-driven runner on an hourly schedule. Each cleanup
operation is a separate retryable queue job, deletes at most 100 records per attempt, and schedules
another small batch when work remains. A terminated process therefore leaves either the current job
or its next generation available to a later request.

With no incoming HTTP traffic, background work waits indefinitely. This follows directly from the
request-driven contract: there is no PHP process to execute code between requests.
`tools/run-background.php` provides a manual recovery/drain command but is not a scheduled
entrypoint. `tools/queue-status.php` reports ready, delayed, failed, oldest-job and active-runner
state as JSON and returns status 2 when dead-letter jobs exist. An operator can requeue one reviewed
dead-letter job with `tools/retry-background-job.php <id> <code>`; bulk blind retries are deliberately
not provided.

## Configuration

Register has two configuration layers:

| Static | Dynamic |
|---|---|
| Stored in `config.php` | Stored in the `config` table |
| Database, filesystem, URL, cache, and environment wiring | Editorial and site behavior |
| Changed by editing deployment configuration | Changed in the control panel |
| Loaded as container parameters | Accessed through `DynamicConfigProvider` |

Product-facing settings will gradually move from inherited `S2_*` names to typed Register settings.
The inherited names are implementation details rather than a compatibility requirement.

## Controllers and presentation

Controllers implement
[`ControllerInterface`](../_include/src/Framework/ControllerInterface.php), receive a matched request,
and return a response. Route matching decides which controller runs.

Page templates define the large-scale HTML structure. Views render individual blocks. Themes can
override presentation, base-module resources are resolved from their `resources` directories by
module class, and optional-module resource lookup remains isolated by validated module identifier.

## Content and URL direction

Posts are the primary content type and pages are a secondary permanent content type. They will share
publication, revision, author, comment, tag, search, feed, and sitemap infrastructure while retaining
type-specific policies such as page hierarchy.

The first unification layer is deliberately storage-independent: `ContentRepository` aggregates a
page source and a blog-post source behind typed IDs and a normalized published-content shape. Search
already uses this contract. The inherited `articles` and `s2_blog_posts` tables remain temporary
adapters until their write paths, comments, and tags are migrated without a flag day.

The blog lives at `/`. Post permalinks are `/<slug>`; publication dates belong to archive navigation,
not post addresses. One canonical URL service must be used by public rendering, the control panel,
RSS, sitemap, search, comments, and notifications.
