# Register ActivityPub protocol profile

- Status: accepted for implementation; external exposure prohibited until the interoperability gate
- Profile version: 1
- Date: 2026-08-21

This document freezes the externally observable identity and lifecycle rules for Register's first
ActivityPub implementation. Operational constants may be tuned without changing the profile when
that does not alter identifiers, authorization, visibility, or state transitions.

Normative references are ActivityPub, ActivityStreams 2.0, WebFinger RFC 7033, HTTP Message
Signatures RFC 9421, and Content-Digest Fields RFC 9530. The module consumes compact ActivityStreams
JSON. It never dereferences an arbitrary JSON-LD context.

## 1. Deployment profile

1. Production actors use an HTTPS canonical origin.
2. A Register installation may live below an origin base path. ActivityPub resource routes include
   that base path, while `/.well-known/webfinger` and `/.well-known/nodeinfo` remain rooted at the
   origin and must route or redirect to Register.
3. The canonical origin and base path are frozen when the first actor is activated.
4. Forwarded host and scheme values are trusted only through Register's configured trusted-proxy
   boundary.
5. Federation is server-to-server. Existing Register sessions authorize local administration and
   author actions; this profile does not expose a generic ActivityPub client-to-server API.

## 2. Identifiers and handles

Every ActivityPub entity identifier is an absolute canonical HTTPS URL. The variable identifier is
16 cryptographically random bytes encoded as 22 unpadded base64url characters. It matches
`[A-Za-z0-9_-]{22}` and is case-sensitive.

| Entity | Identifier |
|---|---|
| Actor | `{base}/activitypub/actors/{id}` |
| Actor inbox | `{actor-id}/inbox` |
| Actor outbox | `{actor-id}/outbox` |
| Followers | `{actor-id}/followers` |
| Following | `{actor-id}/following` |
| Featured | `{actor-id}/featured` |
| Shared inbox | `{base}/activitypub/inbox` |
| Object | `{base}/activitypub/objects/{id}` |
| Activity | `{base}/activitypub/activities/{id}` |
| Key | `{base}/activitypub/keys/{id}` |

Identifiers never include a database primary key, content slug, actor handle, administrator login,
display name, or title. An object's ActivityPub ID is distinct from its canonical human-facing URL;
the object `url` points to the canonical HTML content.

A local handle:

- is normalized to lowercase ASCII;
- matches `[a-z0-9][a-z0-9_-]{0,31}`;
- is independent of a Register login;
- is unique among current handles and retained aliases;
- may change only by preserving the old WebFinger alias and `alsoKnownAs` relationship.

WebFinger accepts `resource=acct:{handle}@{canonical-host}` and the actor URL itself. It returns one
ActivityPub `self` link and the human-facing profile URL. Host comparison uses normalized IDNA ASCII
and the canonical effective port.

## 3. Local actors

### 3.1 Site actor

Every activated installation has one collective actor. Before activation its type is chosen as:

- `Service` for a site or single blog identity; or
- `Organization` for an editorial publication.

The type is immutable after activation. A later product migration may create a replacement actor,
but must not silently change the existing actor document's identity semantics.

The site actor owns posts with no opted-in author and pages selected for federation. In a
multi-author setup it creates `Announce` activities for author-owned posts so following the site
produces a collective feed without misattributing authorship.

### 3.2 Author actor

An opted-in Register author maps to one `Person` actor. Deleting or renaming the Register account
does not reuse or mutate the actor identifier. The actor binding survives as deactivated identity
state until it is explicitly moved or decommissioned.

An author actor owns posts assigned to that author and authenticated replies created as that author.
Anonymous comments and reactions have no ActivityPub actor and never federate.

### 3.3 Actor document

An actor document includes at least:

- ActivityStreams and security contexts;
- `id`, `type`, `preferredUsername`, `name`, and sanitized `summary`;
- `url`, `inbox`, `outbox`, `followers`, `following`, and `featured`;
- the common `endpoints.sharedInbox`;
- the current versioned `publicKey`;
- local avatar and header `Image` attachments when configured;
- `alsoKnownAs` when aliases or migrations exist;
- public profile metadata represented as bounded `PropertyValue` attachments.

Actor JSON is served for `application/activity+json` and
`application/ld+json; profile="https://www.w3.org/ns/activitystreams"`. The human profile is a
separate canonical HTML URL. ActivityPub endpoints do not vary an entity's semantic content by
recipient.

## 4. Collections

Outbox, followers, following, featured, and replies endpoints are ActivityStreams collections.
Collection roots return summary metadata and links to bounded pages. Pages use stable cursor values,
not unbounded offsets, and return at most 40 items by default and 100 items at the hard limit.

Follower and following collections expose `totalItems`. Their membership pages are not public by
default; access policy can reveal them to the local owner without making private recipient lists a
public directory. The collection identifiers remain valid addressing targets regardless of whether
membership is visible.

The outbox contains immutable locally originated activities, newest first. Existing content may be
represented as objects in the actor's history without broadcasting a synthetic backfill unless an
administrator explicitly requests it.

## 5. Content mapping

| Register entity | ActivityStreams object | Notes |
|---|---|---|
| Blog post | `Article` | Default projection |
| Compatibility post | `Note` | Selected before first federation and then frozen |
| Permanent page | `Page` | Discoverable; delivery disabled by default |
| Authenticated author reply | `Note` | `inReplyTo` references the parent object |
| Deleted local object | `Tombstone` | Retains the former object ID and deletion time |

A live content object includes:

- `id`, `type`, `attributedTo`, and canonical `url`;
- `name` for `Article` and `Page`;
- sanitized portable HTML `content`;
- optional content-warning `summary` and `sensitive` marker;
- `published` and `updated` timestamps;
- `to`, `cc`, and optional `audience`;
- `tag` entries for hashtags and mentions;
- bounded media `attachment` entries with URL, media type, name/alt text, dimensions, and blurhash
  only when Register has the corresponding metadata;
- a `replies` collection link;
- language metadata when known.

All local relative URLs in federated HTML become canonical absolute HTTPS URLs. Scripts, styles,
forms, iframes, event handlers, embedded credentials, and unsafe URL schemes are removed. Portable
code and formula markup must remain readable without Register's JavaScript enhancements.

The site or author can select full-content or excerpt delivery. The object always retains its
canonical HTML URL. Changing the delivery mode creates a normal `Update`; it does not change the
object identifier or its frozen object type.

## 6. Addressing and visibility

The ActivityStreams Public collection is `https://www.w3.org/ns/activitystreams#Public`.

- **Public:** `to` contains Public and `cc` contains the owning actor's followers collection.
- **Unlisted:** `to` contains the followers collection and `cc` contains Public.
- **Direct/private note:** recipients are explicit local or remote actors and Public is absent.

Published Register posts and pages support Public and Unlisted only. Followers-only blog
publication is unavailable while its canonical HTML representation remains public.

Inbound `bto` and `bcc` recipients may participate in local authorization but are removed before an
activity is stored in a representation that can be returned or forwarded. Register never expands
arbitrary remote recipient collections. Delivery targets are explicit actors or locally maintained
follower membership.

## 7. Local object lifecycle

Each local content item has zero or more ActivityPub incarnations and at most one live incarnation.

```text
absent --first publish--> live --update--> live
live --unpublish/delete--> tombstoned
tombstoned --republish--> new live incarnation with a new object ID
```

The first publication creates one `Create` activity containing the live object snapshot. Every
material federated change creates a new `Update` activity containing the same object ID and a new
snapshot. Unpublication and deletion create `Delete` and retain a `Tombstone` at the old ID.

An activity identifier is never reused. A serialized activity and object snapshot are immutable
after being scheduled for delivery. Retries sign the same bytes except for transport headers whose
values are intentionally current, such as `Date` and the HTTP message-signature creation time.

Deleting a local database row must not delete its ActivityPub binding, prior activities, or
tombstone. Product lifecycle events provide enough pre-deletion information for the module to write
the tombstone in the same transaction.

## 8. Follow lifecycle

Incoming and outgoing relationships are stored separately and are unique by local actor and remote
actor.

```text
none -> pending -> accepted
              \-> rejected
pending/accepted -> cancelled by Undo or local removal
```

An incoming `Follow` may be auto-accepted by policy or remain pending for moderation. `Accept` and
`Reject` reference the exact original `Follow`. `Undo` is valid only when its actor matches the
actor of the activity being undone.

An accepted incoming follow stores the follower's personal inbox and optional shared inbox as
discovered from a verified actor document. Delivery prefers one shared inbox per origin while
retaining the logical recipients for audit and future fan-out changes.

## 9. Inbox processing

The synchronous POST boundary:

1. accepts only a supported ActivityStreams media type;
2. enforces a 1 MiB body limit, a JSON nesting limit of 32, header limits, and a valid top-level
   object shape;
3. requires a recognizable HTTP signature envelope and a verifiable body digest when a body exists;
4. applies coarse IP and origin rate limits;
5. stores the bounded raw request, selected transport metadata, and a SHA-256 body hash;
6. returns `202 Accepted` after durable persistence.

If a known cached key can be used within the foreground budget, a signature may be verified before
persistence. A cache miss never causes an outbound fetch in the foreground request. Shutdown work
fetches and verifies the actor/key through the safe remote client before applying any side effect.

Inbox rows are idempotent by normalized activity ID and additionally record the body hash. Reusing
an activity ID with different bytes is a security conflict, not an update.

Supported inbound activity families are:

- `Follow`, `Accept`, `Reject`, and `Undo`;
- `Create`, `Update`, and `Delete` for supported objects;
- `Like`, emoji reaction, `Announce`, and their `Undo`;
- `Block`, `Flag`, and `Move`;
- `Add` and `Remove` for supported featured-collection behavior.

Unsupported well-formed activities are acknowledged and recorded as ignored. They have no product
side effects.

## 10. Authorization rules

- A `Follow` actor must equal the actor being added to the relationship.
- A `Create` actor must be authorized for the embedded object's `attributedTo`. Register initially
  accepts equality and does not infer arbitrary delegation.
- An `Update` or `Delete` actor must be the verified owner already stored for the target object.
- An `Undo` actor must equal the original activity actor.
- A `Like` or `Announce` actor must equal the activity actor; the referenced object may be remote.
- A remote reply becomes a comment only when `inReplyTo` resolves to a live local object or a known
  comment in its thread.
- `Move` requires the old actor to originate the activity, the target actor to name the old actor in
  `alsoKnownAs`, and successful signed retrieval of both actor documents.
- A signing key must be linked from the actor document or otherwise verifiably controlled by that
  actor. A syntactically valid signature from an unrelated key is rejected.

Local moderation can hide or edit the local presentation of a remote object, but it cannot emit an
`Update` pretending to be the remote actor.

## 11. HTTP signatures and signed fetches

The compatibility signing key is RSA-2048. RSA signatures use SHA-256 with PKCS#1 v1.5. RSA-PSS is
not used for the legacy ActivityPub signature.

Legacy outgoing POST signatures cover `(request-target)`, `host`, `date`, and `digest`. Legacy
outgoing signed GET covers `(request-target)`, `host`, and `date`. The `keyId` is the current
versioned key URL, and the compatibility algorithm token is `rsa-sha256`.

RFC 9421 verification supports signatures covering at least `@method`, `@target-uri`, and
`content-digest` for requests with a body. Signature creation time is required. The outgoing RFC
9421 profile uses `rsa-v1_5-sha256` and the same covered components, with `content-type` added when a
body is present.

The verifier:

- validates the digest before product side effects;
- accepts at most five minutes of ordinary clock skew;
- rejects expired signatures and implausible creation times;
- reconstructs the exact request target without lossy URL normalization;
- performs constant-time digest comparisons;
- refreshes a stale actor/key once after verification failure;
- never logs private key material, full authorization headers, or sensitive recipient fields.

Signed GET is available for actor, object, and collection retrieval when a peer requires secure
mode. The authorization decision remains based on the requested resource; a valid signature alone
does not grant access to private data.

## 12. Delivery lifecycle

One delivery exists for each immutable activity and effective remote inbox. A SHA-256 URL key backs
the unique database constraint while the complete URL remains stored.

```text
pending -> in-flight -> delivered
                    \-> delayed -> in-flight
                    \-> failed
                    \-> cancelled
```

The database runner lease prevents ordinary concurrent workers. State transitions and a per-delivery
claim token still make recovery safe if PHP is killed during a network request.

Response classification:

- any `2xx`: delivered;
- `404` or `410`: cancelled as a gone endpoint and actor refresh/removal is scheduled;
- `401` or `403`: refresh actor and key once, then fail as authorization;
- `429`: delayed according to a bounded valid `Retry-After` value;
- `408`, `425`, `5xx`, DNS failure, connection failure, and timeout: delayed with exponential
  backoff and jitter;
- invalid URL, blocked address, permanent TLS policy failure, or invalid peer response: failed.

The initial policy allows twelve expected network attempts over no more than seven days. These
attempts live in the delivery table and republish future queue generations; unexpected programming
or database failures still use the general queue's dead-letter policy.

No handler sleeps. It performs at most one independently repeatable network step before a budget
checkpoint. cURL connect and total timeouts are derived from the remaining monotonic shutdown
budget with a safety margin.

## 13. Remote content and local presentation

Remote actor and object documents are immutable input snapshots plus separately maintained current
state. Long remote URLs are never used directly as portable unique-index columns; SHA-256 URL keys
are used for uniqueness.

Remote replies imported as comments:

- contain no invented IP address, email address, or subscription state;
- retain remote actor, object, activity, and canonical source URLs in module-owned provenance;
- use a locally cached, bounded avatar rather than public hotlinking;
- enter moderation unless actor/domain policy explicitly trusts them;
- accept a later `Update` or `Delete` only from the stored owner;
- remain locally tombstoned after remote deletion.

Every remote `Like`, emoji reaction, and `Announce` is stored individually so `Undo` can remove the
correct contribution. Public totals are derived through a Register reaction-aggregate capability.

Directly addressed notes are stored in a private administrative inbox, never rendered as public
comments, and labelled as unencrypted federated messages.

## 14. Network security

Every outbound URL passes the common safe remote HTTP boundary:

- only HTTPS in production;
- no embedded username or password;
- no proxy inherited implicitly from the hosting environment;
- all resolved addresses must be public and non-reserved;
- mixed public/private DNS answers are rejected;
- the approved address is pinned for the connection;
- every redirect is resolved, revalidated, and repinned;
- HTTPS-to-HTTP downgrade is rejected;
- credentials and authorization headers are stripped on origin change;
- connect, total-time, redirect, header, and response-body limits are mandatory;
- actor and object responses are limited to 1 MiB; collection pages are limited to 2 MiB;
- remote media uses stricter type, byte, pixel, and decompression limits.

JSON-LD processing recognizes the ActivityStreams vocabulary and explicitly supported extensions.
It never fetches a context URL. Remote HTML uses an allowlist and canonical URL validation before it
reaches a template.

Rate limits and circuit-breaker timestamps are database state keyed by IP, actor, and effective
origin. They never require Redis and never block a PHP process waiting for a slot.

## 15. Retention and audit

- Successfully processed raw inbox bodies are removed after seven days.
- Rejected or failed raw inbox bodies are retained for 30 days unless an administrator removes them
  earlier; diagnostic views redact sensitive recipients and signature values.
- Delivery attempt detail is compacted after 90 days while the immutable activity and final outcome
  remain available.
- Unreferenced remote cache objects may be pruned after 90 days. Followed actors, active threads,
  moderation evidence, and interaction provenance remain referenced.
- Local actors, aliases, keys needed to verify history, content bindings, activities referenced by
  lifecycle state, and tombstones are not removed by routine maintenance.

Private keys and the master encryption key never appear in ordinary logs, raw activity audit,
client assets, or public diagnostics.

## 16. Module lifecycle

The module has four operational states:

1. **Installed:** schema exists; no public identity has been activated.
2. **Active:** discovery, actors, inbox, outbox, and delivery operate normally.
3. **Paused:** public identity, objects, activities, and tombstones remain readable; new inbound
   social side effects and outbound delivery are held.
4. **Decommissioned:** deletion/move workflow has completed; tombstone and migration responses remain
   available for the configured retention period before the optional module may be disabled.

Ordinary module disable is safe before activation. After activation, administration requires an
explicit pause or decommission workflow rather than presenting disable as a harmless toggle.
Disabling never drops module tables or private key material.

## 17. Activation and interoperability gate

Activation requires successful checks for:

- canonical HTTPS and trusted-proxy resolution;
- root WebFinger and NodeInfo routing;
- local secret storage and RSA generate/encrypt/decrypt/sign/verify round-trip;
- an external fetch of the actor document;
- a signed request round-trip to the shared inbox;
- queue lease and shutdown execution health.

Release builds keep activation unavailable until automated Register-to-Register coverage and the
declared Mastodon, GoToSocial, Akkoma, Misskey/Sharkey, WordPress ActivityPub, Ghost, and WriteFreely
interop scenarios pass for discovery, follow, create, update, delete, reply, like, announce, undo,
signed fetch, retry, and duplicate delivery.

Identity and object URI rules in this profile cannot be changed after that gate without a versioned
migration preserving all old URLs.
