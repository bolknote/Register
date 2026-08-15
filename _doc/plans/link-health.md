# Plan: link health module

## Goal

Build a mandatory Register module that inventories links in published content, checks every unique
external target only once per interval, finds Wayback Machine copies for confirmed broken targets,
and safely repairs the stored content. Local links are indexed but never fetched; they are enforced
when their target content is deleted.

The database is a persistent, rebuildable link index, not a generic SQL query cache.

## Product decisions

- Index canonical stored `body` HTML, not rendered pages, navigation, theme output, or request-time
  HTML.
- Track `<a href>` links in the first version. Media resources can be added as a separate concern.
- Store Wayback replay links but never probe or rewrite them.
- Store local links and resolve them to a `content.id`, but never probe them over HTTP.
- Deduplicate probe work by a normalized target hash. Per-content occurrences remain separate.
- Keep query strings. Remove fragments only from the probe key and preserve them in each repair.
- Treat one failed request as evidence, not a verdict.
- `401` and `403` mean restricted but present; throttling, timeouts, DNS/TLS failures, and `5xx` are
  initially suspect. Confirm `404`/`410` or repeated network failure before declaring a target broken.
- Confirmed broken targets leave the ordinary schedule. Administrators can request a recheck.
- Look up an archive only after the target is confirmed broken.
- Automatic repair is configurable and enabled by default. It requires a confirmed broken state, an
  accessible Wayback result with status `200`, and an unchanged content revision.
- Reparse current HTML at repair time and replace only matching `href` attributes. Never store byte
  offsets and never run a raw global string replacement.
- Record every check and repair for auditability.
- Give each probe/archive queue operation a stable random token. Persist the token with its result so
  a crash after commit but before queue acknowledgement cannot apply the same result twice.
- Commit each result and its archive/repair follow-up in one short database transaction. Use an
  optimistic target-state guard so a concurrent administrative decision is not overwritten.
- Admit at most one Wayback Availability API request per installation every 15 seconds. Never sleep
  in a worker: atomically claim a global slot or republish the job with a future `available_at`, so
  normal `register_shutdown` queue slices advance the backlog gradually.
- Persist HTTP probe progress (`HEAD`, bounded `GET` fallback, and redirect count) in the queue and
  make at most one remote request per job generation. Republish continuations instead of keeping a
  shutdown worker inside an entire redirect chain.
- Admit at most one ordinary external request per host every two seconds. Store independent host
  slots in SQL and defer queue generations instead of sleeping.
- Send non-blocking A/AAAA datagrams to the host's resolvers from `/etc/resolv.conf` with a one-second
  hard deadline. Never invoke libc DNS, a child process, PHP CLI, or a public third-party resolver.
  Resolver transport failures can never turn a URL into a broken link.
- Use leased `register_shutdown` slices as the only production scheduler. There is no cron or CLI
  dependency; unfinished work always becomes another durable queue generation.
- Block deletion of a post or page branch when content outside the deletion set has indexed local
  links to it.
- Never perform network work while rendering or saving a public/admin page.

## Storage

### `register_link_target`

One row per normalized target:

- normalized URL and SHA-256 key;
- kind: external, local, or archive;
- host and resolved local content ID where applicable;
- health status, HTTP status, failure count, effective redirect URL, and last error;
- first/last occurrence, last check, last successful response, and next scheduled check;
- Wayback status, replay URL, capture timestamp, and lookup time.
- token of the last committed archive operation for retry idempotency.

### `register_content_link`

One row per `(source_content_id, target_id)` with representative original `href`, occurrence count,
indexed content revision, and first/last occurrence times. Foreign keys cascade when the source or
target index row is removed.

### `register_link_check`

Append-only recent probe history with bounded retention and a unique operation token.

### `register_link_repair`

Audit trail containing content/target IDs, old target, Wayback replacement, occurrence count,
revision before/after, and repair time.

### `register_link_throttle`

One coordination row per rate-limited remote service. The Wayback row and hashed per-host rows store
the earliest permitted request time and are claimed with atomic compare-and-swap updates across
workers and application nodes. Stale host rows are pruned by request-driven periodic maintenance.

## Components

1. `HtmlLinkExtractor`, `LinkUrlNormalizer`, and `ContentPathResolver` classify and deduplicate links.
2. `LinkInventory` synchronizes one content item after `ContentChangedEvent`; an incremental queue job
   backfills existing published content.
3. A tagged periodic-maintenance task, invoked from an HTTP shutdown slice rather than cron,
   enqueues due unique external targets without replacing an already queued generation.
4. `SafeHttpProbe` resolves each redirect through hard-deadline non-blocking DNS, pins public IP
   addresses, blocks local/private/reserved networks, limits redirects, response bytes, and
   timeouts, and advances one durable `HEAD`/capped-`GET` step per queue generation.
5. `LinkCheckQueueHandler` claims the target host slot, classifies probe results, and atomically
   records confirmed failures with an idempotent archive-lookup follow-up.
6. `WaybackClient` uses the Availability API with the last known-good/observation time and accepts
   only an available status-200 replay URL.
7. `LinkRepairQueueHandler` updates matching stored `href` attributes with an optimistic revision
   guard, records the repair, and dispatches the normal content-changed event.
8. The admin service page shows summary counts, targets, last successful response, last check,
   status, uses, errors, and archive candidates. It provides recheck, ignore/unignore, and repair
   actions with permission and CSRF checks.
9. The admin config extender attaches deletion guards to pages and blog posts.

## Delivery checklist

- [x] Add the module registry entry, schema, defaults, and schema-generation bump.
- [x] Add URL normalization, HTML extraction/rewriting, local resolution, repositories, and tests.
- [x] Synchronize inventory on content changes and incrementally backfill existing content.
- [x] Add request-driven periodic-maintenance tasks and unique due-target publication.
- [x] Add bounded, SSRF-safe HTTP probing and result classification.
- [x] Add Wayback lookup, optimistic repair, audit records, and tests.
- [x] Add the admin page, actions, translations, styles, and local deletion guard.
- [x] Run focused tests, full unit/integration tests, lint, and static analysis; fix regressions.
- [x] Pace Wayback lookups globally through durable queue deferral and the shutdown runner.
- [x] Make external HTTP probes resumable within the five-second shutdown-runner budget.
- [x] Make probe/archive result commits and their follow-ups transactional and retry-idempotent.
- [x] Bound DNS inside web PHP without libc DNS, subprocesses, CLI, or third-party resolvers.
- [x] Pace ordinary external checks independently per host and prune stale throttle rows.
- [x] Keep leased `register_shutdown` slices as the sole production scheduling path.

## Verification

- Link-health integration scenarios: 12 tests, 84 assertions.
- Full suite: 259 unit tests and 168 integration tests passed.
- HTTP-client acceptance suite: 57 tests, 109 assertions.
- Syntax/style lint, PHPStan, Psalm, dependency analysis, PHPMD, PHP compatibility, Phan, Rector,
  and `git diff --check` passed.

## Acceptance criteria

- The same normalized external URL in multiple materials produces one target and one probe job.
- Fragment variants share a probe target but keep their fragments after archive replacement.
- Existing Wayback, local, anchor-only, mail, telephone, script, and data links are not probed.
- A single `404`, timeout, `429`, or `5xx` cannot edit content.
- `401`/`403` never become broken merely because access is denied.
- Redirects are validated hop by hop and cannot reach private or reserved addresses.
- Concurrent Wayback jobs cannot start multiple API requests inside the global 15-second interval.
- Ordinary checks to the same host cannot start inside the two-second host interval; different hosts
  remain independent.
- A five-second shutdown runner advances an external check by one request and preserves unfinished
  `HEAD`/`GET` or redirect state for a later run.
- DNS cannot hold a worker past its one-second deadline, and a missing/unreachable system resolver
  does not count as remote-link failure.
- Retrying a committed queue generation cannot duplicate check history, failure counts, or follow-up
  jobs; failure to enqueue a follow-up rolls the result back.
- Normal operation requires no cron and no PHP CLI; every unfinished step is resumable on a later
  HTTP shutdown slice.
- A stale content revision cannot be overwritten by background repair.
- Repair changes only matching anchor `href` values and emits the normal content lifecycle event.
- Deleting a locally referenced target is rejected with a useful message.
- Administrators can distinguish "last seen in content" from "last successful response".
