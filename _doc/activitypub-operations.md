# ActivityPub on shared hosting

This runbook covers installation, activation, day-to-day operation, privacy, backup, recovery, and
identity migration for Register's first-party ActivityPub module. The normative identity and
authorization rules live in the [protocol profile](activitypub-protocol-profile.md); this document
is for operators.

The module is deliberately request-driven. It does not require or use Redis, a worker daemon,
Node.js, an external cron entry, or a production PHP CLI. Durable work is stored in the configured
PDO database and advances in bounded `register_shutdown_function` slices after normal HTTP
responses.

## Runtime requirements

Before installing the module, verify the normal Register requirements plus:

- PHP 8.3 or newer with PDO, Sodium, DOM, JSON, and Fileinfo;
- a working HTTPS client; cURL with libcurl TLS is the supported shared-hosting path when
  `ext-openssl` is absent;
- outbound HTTPS and DNS access from the PHP hosting account;
- a writable private cache directory and private dynamic-secret location;
- SQLite, MySQL/MariaDB, or PostgreSQL.

`ext-openssl` is not an ActivityPub dependency. `phpseclib/phpseclib ^3.0.56` performs RSA-2048,
SHA-256, and PKCS#1 v1.5 operations through the module's `RsaCrypto` adapter. Private RSA keys are
encrypted with Sodium before they reach the database. Do not deploy a phpseclib development branch
to obtain 4.x early; upgrade the isolated adapter only after a stable 4.x release has passed the
same cryptographic and interoperability suites.

The shared-hosting distribution must include Composer's configured `_vendor/` directory. Running
Composer on the hosting account is not required.

## Install first, activate later

Installing the optional `activitypub` extension creates its schema and master secret, but exposes no
public actor. This separation is intentional: actor, object, activity, and key URLs become permanent
external identities as soon as a peer discovers them.

Activation is a two-phase operation:

1. Open **ActivityPub** in the administration area and prepare the site actor.
2. Choose the immutable canonical HTTPS origin, base path, actor type, and handle.
3. Let the request-driven queue complete all readiness probes.
4. Review every result and type the explicit activation phrase.

The setup probes cover local cryptography and secret storage, root WebFinger, base-path routing,
external actor retrieval, a signed inbox round-trip, and the exact release interoperability gate.
Activation remains unavailable while any probe fails. A source checkout intentionally contains only
`.dist` interoperability templates; release artifacts must carry genuine, hash-linked results as
described in [the interoperability runbook](activitypub-interoperability.md).

After activation, the canonical origin and base path cannot be edited as ordinary settings. Use the
Move workflow for a real identity migration.

## Existing blog integration and defaults

ActivityPub is an additional representation of the existing Register blog, not a replacement for
it. Ordinary HTML pages, canonical URLs, RSS, local comments, local reactions, search, and the
editorial workflow continue to operate without depending on federation. Installing the module does
not rewrite content rows, and activating it does not broadcast historical material.

The site manager can open **ActivityPub → Existing blog integration** before or after activation and
choose the defaults used on the next content save:

- whether blog posts are federated by default;
- whether ordinary pages are federated by default;
- `Article` or compatibility `Note` for newly federated posts;
- full portable HTML or an excerpt followed by the canonical link;
- Public or Unlisted visibility.

The conservative initial policy federates new blog posts as full Public `Article` objects and keeps
ordinary pages out of federation. The policy form itself never enqueues Create, Update, or Delete
and never sweeps existing content. A content item's explicit ActivityPub panel always overrides the
site default. If an already-federated item inherits a newly disabled default, it remains untouched
until that item is next saved; the exact preview then shows the resulting Delete before the editor
commits it.

Remote replies enter Register through the normal comment import and moderation path, while local
comments remain ordinary blog comments. Anonymous local comments and reactions are never assigned a
fabricated actor and therefore are not sent to the federation. Authenticated author actors can use
the private ActivityPub reader for replies, reactions, and reposts without changing the public blog
interface.

## Root `/.well-known/` routing

WebFinger and NodeInfo are rooted at the origin even when Register lives in a subdirectory. For an
installation at `https://example.org/register/`, requests to both of these URLs must reach Register:

```text
https://example.org/.well-known/webfinger
https://example.org/.well-known/nodeinfo
```

The setup screen generates the exact Apache rewrite for the configured base path. A typical rule in
the document-root `.htaccess` is:

```apache
RewriteEngine On
RewriteRule ^\.well-known/(webfinger|nodeinfo)$ /register/index.php [END,QSA]
```

Adjust `/register` to the real base path. Put the rule in the origin's document root, not only in the
Register subdirectory. Do not redirect WebFinger to another hostname, expose an internal port, or
drop the query string.

For a reverse proxy, route those two exact paths to the same Register front controller as the
configured public base path. Preserve the original HTTPS host. Forwarded host, scheme, and client IP
are trusted only when the proxy's concrete IP or narrow CIDR is listed in `http.trusted_proxies` in
`config.php`, for example:

```php
'http' => [
    'trusted_proxies' => ['203.0.113.10/32'],
],
```

Never use a universal trusted-proxy range merely to make an activation check pass. A client that can
forge trusted forwarded headers can otherwise change security-sensitive origin and rate-limit data.

Useful preflight requests are:

```bash
curl -i 'https://example.org/.well-known/webfinger?resource=acct:blog@example.org'
curl -i 'https://example.org/.well-known/nodeinfo'
```

Before activation a draft actor remains intentionally undiscoverable; run the probes from the setup
screen so they carry the temporary activation capability.

## Request-driven queue contract

Foreground publishing writes the immutable ActivityPub object/activity and delivery intent in the
same database transaction as the local content change. Inbox POSTs persist a bounded first-seen
envelope and promptly return `202`. Neither path performs remote discovery or delivery before the
HTTP response.

After the response, the shared shutdown runner claims a small amount of work. An ActivityPub handler
does at most one independently repeatable network hop and persists the next state. Redirects,
authenticated retries, Retry-After delays, and backoff are separate queue generations. No handler
uses `sleep()`.

If the site receives no requests, delayed work waits for the next request. This is the normal shared-
hosting contract, not data loss. An administrator can press **Push queue** to generate a safe request;
the button does not create a second worker implementation. Do not add an overlapping ad-hoc cron
drain unless Register's queue architecture itself is changed and tested for that execution model.

The ActivityPub dashboard shows:

- ready, delayed, and failed module deliveries;
- failed inbox envelopes and locally cached remote media;
- the oldest ready job and the shared runner lease;
- the last ActivityPub handler that actually entered through the shutdown runner;
- inbound and outbound failures grouped by effective origin;
- pending remote replies, active Flags, and explicit moderation rules;
- the byte size of retained remote JSON snapshots plus published local avatar mirrors;
- actor/key recovery health and an identity fingerprint.

The generic queue's `runner active` flag and the ActivityPub `last runner` timestamp answer different
questions. The first is a short lease observation; the second proves that module work has run after
an HTTP response. A quiet, fully drained installation can legitimately have an old ActivityPub
timestamp.

## Delivery failures

Module delivery states classify expected network behavior without exhausting the generic queue's
programming-error retry budget:

| Observation | Durable result |
|---|---|
| Any `2xx` | Delivered |
| `404` or `410` | Endpoint gone/cancelled |
| First `401` or `403` | Refresh actor/key and retry once |
| Repeated `401` or `403` | Permanent authorization failure |
| `429` | Delayed to a bounded valid `Retry-After` |
| `408`, `425`, `5xx`, DNS, connect, timeout | Exponential backoff with jitter |
| Unsafe URL, private address, invalid peer document | Permanent failure before side effects |

Each redirect is stored and handled as another DNS-resolved, public-address-pinned hop. Never work
around an SSRF rejection by allowlisting loopback, RFC 1918, link-local, or mixed public/private DNS.
Fix the peer URL or DNS instead.

When diagnosing one domain:

1. Inspect whether failures are inbound or outbound and note their last timestamp.
2. Check the peer's HTTPS certificate and public DNS from the hosting network.
3. Confirm that its actor advertises the inbox currently stored by Register.
4. For authorization failures, rotate neither local nor remote keys blindly; let the one bounded
   actor refresh complete and inspect the resulting dead letter.
5. Preserve the failed row and logs until the cause is understood. Logs intentionally omit private
   keys, Authorization values, signature bytes, and private recipient lists.

## Authors and permissions

A user with article-creation permission can opt in and manage only the `Person` actor bound to that
same Register author ID. The author can discover/follow remote actors, read their own reader, reply,
react, announce, rotate their key, change their handle, and initiate Move for that actor.

Only a user with site-edit permission can operate the collective actor or perform site-wide setup,
activation, pause/resume, backfill, queue wake-up, moderation policy changes, diagnostics, or
decommission. Every action is checked again at the mutation endpoint; hiding controls in the HTML is
not the authorization boundary.

ActivityPub handles are independent of administrative logins. Do not copy a login name into a
handle merely for convenience if that would disclose an otherwise private account identifier.

## Moderation and privacy

Remote replies default to moderation. Explicit rules can target an exact actor, HTTPS origin, or
domain and choose `moderate`, `trust`, `silence`, or `block`. Higher-priority and newer matching
rules win deterministically. Blocking prevents new social side effects and Mention delivery; it
does not forge an Update or Delete as the remote actor.

Remote replies imported into Register comments keep provenance but receive no invented email, IP,
or subscription state. Their avatars are fetched after the response through the SSRF-safe client,
strictly inspected, stored in a private cache, and served from a same-origin controller. Public page
views never hotlink a remote avatar and therefore do not disclose visitor IP addresses to the peer.

Direct notes are visible only in the selected local actor's private administrative reader. They are
ordinary server-to-server ActivityPub messages, not end-to-end encrypted messages. The UI labels
them accordingly. `bto` and `bcc` may be used for authorization but are removed from retained or
forwardable snapshots.

Raw successfully processed inbox bodies are redacted after seven days. Failed evidence is retained
longer for diagnosis. Detached remote snapshots, delivery-attempt detail, notifications, and avatar
files follow bounded housekeeping rules; actor identity, aliases, immutable activities needed for
audit, and tombstones are not routine cache entries.

## Historical content

Activation federates new publications and later changes by default. Existing content is never
silently blasted at newly discovered peers. A site manager may queue the latest N items or explicit
content IDs. Backfill processes exactly one item per shutdown generation and stores it in outbox
history without follower delivery.

If a historically projected object later transitions into normal live broadcast, Register emits a
real `Create` (and the collective `Announce` where appropriate) at that transition. Its object ID
does not change merely because delivery became live.

## Keys, backup, and recovery

Each actor has a versioned RSA key URL and exactly one current private key. Rotation atomically
creates and verifies a replacement while retaining the old public key for historical verification.
Do not delete old key rows or generate a new actor because a remote peer has a stale cache.

Every encrypted Register backup contains the database, media, and
`extensions/activitypub/identity-recovery.json`. That recovery document contains the ActivityPub
master key and is therefore protected only by the backup envelope. Keep the archive encrypted and
follow [the main backup runbook](backups.md), preferably with an offline recipient key.

For a normal restore, restore the matching database and the separately preserved `config.php` and
`config.secrets.php`, then open ActivityPub diagnostics and compare the identity fingerprint. If the
database is intact but the ActivityPub entry in `config.secrets.php` is missing:

1. Keep the site offline so no outgoing signature is attempted.
2. Decrypt the matching backup and extract
   `extensions/activitypub/identity-recovery.json` to a private file.
3. Restore the database first.
4. Run:

   ```bash
   php tools/restore-activitypub-identity.php /private/identity-recovery.json
   ```

5. The tool compares the recovery document's identity fingerprint to the restored database,
   decrypts every non-destroyed key, signs and verifies a probe, and only then replaces the master
   secret. A document from another database fails closed.
6. Delete the extracted plaintext recovery file, clear the normal Register cache, bring the site
   online, and confirm a healthy identity dashboard.

If PHP CLI is unavailable, restore the matching `config.secrets.php` through the hosting provider's
private file manager instead. Never paste the master key into SQL, ordinary configuration fields,
logs, tickets, or chat. Losing both the secret file and every authenticated encrypted recovery
archive makes the existing private actor keys unrecoverable; Register intentionally does not create
a silent replacement identity.

## Pause, handles, Move, and decommission

**Pause** keeps actor, key, object, activity, and tombstone URLs readable while holding new social
effects and delivery. Use it for maintenance, investigation, or a controlled migration.

A handle change retains the old WebFinger handle as an alias and emits actor `Update`; the immutable
actor URL and keys stay the same. Do not reuse a retired handle for another actor.

For a domain or actor migration, use **Move** only after the destination actor is reachable and lists
the old actor in `alsoKnownAs`. Register signed-fetches and validates the reciprocal relation before
persisting the Move. Keep the old origin and its actor/key/tombstone routes available throughout the
migration window; DNS or HTTP redirects alone are not an ActivityPub identity migration.

**Decommission** is destructive external communication, not ordinary module disable. It queues
Delete activities, waits for outstanding claims/deliveries, and leaves tombstone state. Only after
that lifecycle completes may the generic extension manager disable the module. Disabling or
uninstalling never drops identity tables or private key material automatically.

## Upgrade checklist

Before deploying an ActivityPub module update:

1. Create and copy an encrypted backup off-site.
2. Confirm identity health and record the fingerprint.
3. Let the existing queue drain or pause federation.
4. Deploy application files and the matching `_vendor/` tree as one release.
5. Let the idempotent schema migrator run; do not manually edit `profile_version`.
6. Open diagnostics, verify identity health and queue status, then resume if paused.
7. For a release that changes protocol behavior or dependencies, require a fresh exact-version
   interoperability attestation. An attestation for another module version is rejected.

Never downgrade a database whose ActivityPub schema or public identity semantics were written by a
newer release.
