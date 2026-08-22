# ADR 0002: ActivityPub is a database-backed optional federation module

- Status: accepted
- Date: 2026-08-21

## Context

Register publishes canonical HTML and RSS from a compact PHP application intended to run on
ordinary shared hosting. Its background work is already durable, database-backed, and advanced in
bounded `register_shutdown_function` slices after successful HTTP responses. Register deliberately
does not require a daemon, cron entrypoint, Redis, Node.js, or PHP CLI in production.

ActivityPub adds permanent public identities, signed server-to-server requests, fan-out delivery,
remote actor discovery, retries, inbound activities, and moderation. A partial implementation can
create identities or object identifiers that cannot later be repaired without breaking followers
and remote references. The protocol therefore needs a stable product profile before public actors
are exposed.

ActivityPub is an integration rather than part of the minimum publishing model. Under ADR 0001,
integrations belong to the optional-module tier and must consume public Register capabilities rather
than query base product tables directly.

## Decision

Register will ship a first-party optional module with the identifier `activitypub`.

The module implements the ActivityPub server-to-server protocol for a blog-native product. Generic
client-to-server OAuth, the Mastodon REST API, a global Fediverse search engine, relays, and an
encrypted messenger are outside this module's contract.

### Runtime and persistence

- PDO storage is the only coordination layer. Module tables store actors, keys, aliases, object
  bindings, immutable activities, inbox items, deliveries, follows, remote caches, interactions,
  moderation rules, and notifications.
- The existing queue, database lease, generation-aware acknowledgement, and shutdown runner execute
  all background work. The module does not add Redis, a worker daemon, an external cron requirement,
  or a blind queue-drain command.
- A foreground publication writes its ActivityPub outbox intent in the same database transaction as
  the publication lifecycle change. It performs no remote I/O.
- An inbox request performs bounded envelope checks, persists the request, returns promptly, and
  defers signature verification requiring remote key retrieval and all side effects to shutdown
  work.
- Expected delivery failures are state transitions in the module delivery table. They do not consume
  the general queue's retry allowance. No handler sleeps; it republishes a future generation.
- A site with no requests does not advance delayed federation work. This is the same intentional
  shared-hosting trade-off as every other Register background task.

### Identity

- A site has one collective actor. Its default type is `Service`; an editorial installation may
  choose `Organization` before activation.
- Each opted-in Register author has a separate `Person` actor. ActivityPub handles are independent
  of administrator logins.
- An author actor owns the author's posts. The collective site actor announces author-owned posts to
  provide a standards-based aggregate blog feed. Content without an opted-in author is owned by the
  site actor.
- Actor, key, object, and activity identifiers contain random 128-bit URL-safe identifiers. They do
  not contain mutable handles, slugs, titles, administrator logins, or database identifiers.
- The canonical HTTPS origin and base path are part of every public identifier. They are immutable
  after activation except through an explicit `Move` and `alsoKnownAs` migration.
- Unpublishing or deleting an object emits `Delete` and leaves a `Tombstone`. Republishing the same
  local content creates a new ActivityPub incarnation and object identifier.

### Content and interactions

- Posts are `Article` by default. A compatibility `Note` projection may be selected before an object
  is first federated. Permanent pages are `Page` and are not broadcast by default.
- Published blog content supports Public and Unlisted addressing. Followers-only publication is not
  claimed while the canonical HTML page remains publicly accessible.
- Authenticated local-author replies can federate as `Note`. Anonymous comments and anonymous
  reactions remain local because they have no actor or signing key.
- Remote replies can become moderated local comments through a public import capability. Remote
  `Like`, emoji reaction, `Announce`, and corresponding `Undo` activities retain individual
  provenance so aggregates can be reversed correctly.

### Cryptography and transport

- Stable `phpseclib/phpseclib ^3.0.56` supplies RSA without requiring `ext-openssl`. Version 4 is
  not selected while it is available only as a development branch. One adapter owns every
  phpseclib namespace/API call so a later stable 4.x migration is local. The compatibility
  signature is RSA-2048, SHA-256, PKCS#1 v1.5.
- Incoming requests support the widely deployed legacy HTTP Signature format and RFC 9421 HTTP
  Message Signatures. Legacy RSA-SHA256 remains the default outgoing compatibility format; RFC 9421
  outgoing support is selected only for peers known to accept it.
- Each actor has versioned key identifiers. Private RSA material is encrypted with
  `sodium_crypto_secretbox`; the independent master key resides in Register's protected secret
  storage.
- Production federation requires a working HTTPS transport. The supported shared-hosting baseline
  without `ext-openssl` is PHP cURL backed by libcurl TLS.
- Remote discovery and delivery use a reusable SSRF-safe HTTP capability: public-address validation,
  DNS pinning, manual redirect validation, HTTPS downgrade protection, response limits, and a
  deadline derived from the queue execution budget.

### Lifecycle and release

- Enabling the installed module does not immediately publish an actor. An explicit activation flow
  freezes the origin and identity settings after external self-tests pass.
- Pausing federation leaves identity and tombstone endpoints available while stopping new social
  side effects and deliveries.
- Decommissioning is distinct from disabling. It emits the appropriate deletion activities and
  leaves recoverable tombstone state before routes can disappear.
- No public actor is exposed from a release build until Register-to-Register tests and the declared
  cross-implementation interoperability matrix pass. The exact release bundles both a strict
  machine-readable result summary and an attestation containing the SHA-256 of those exact bytes;
  activation rejects missing, symlinked, incomplete, wrong-version, or hash-mismatched artifacts.

## Consequences

- Register needs public author-profile, rich content-description, comment lifecycle/import, reaction
  aggregate, secure remote HTTP, and secret-backup capabilities before the optional module can be
  implemented without reaching into base tables.
- Optional-module migrations own all ActivityPub-specific storage and preserve it while disabled.
- Root-level WebFinger routing must be configured even when Register is installed in a subdirectory.
- Database growth needs explicit retention for processed inbox bodies, remote cache entries, and
  delivery history. Actor identities, aliases, object bindings, activities needed for audit, and
  tombstones have longer or permanent retention.
- The shared-hosting build includes phpseclib. The ActivityPub activation preflight rejects a host
  without a viable HTTPS client but does not impose `ext-openssl` on the base application.
- Identity-affecting changes require migrations rather than ordinary editable settings.

## Rejected alternatives

- **Make ActivityPub a base module.** Federation is an integration and has permanent external state;
  installations that do not use it should not expose endpoints or maintain its tables.
- **Use content slugs or user logins as IDs.** Both are mutable and would make normal editorial or
  account changes break remote references.
- **Perform delivery in the publication request.** A slow or unavailable peer would make publishing
  unreliable and violate Register's background-work boundary.
- **Require Redis or a daemon.** This would abandon Register's shared-hosting deployment contract
  without providing protocol correctness that the existing durable database queue cannot provide.
- **Require `ext-openssl` for RSA.** Pure-PHP RSA is adequate for the bounded volume of a personal or
  small editorial blog, while cURL can provide HTTPS transport independently.
- **Expose an early minimal actor.** Once discovered, its IDs and semantics are public compatibility
  promises. Internal milestones remain hidden until the release gate.
