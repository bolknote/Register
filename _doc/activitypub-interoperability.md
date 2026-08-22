# ActivityPub interoperability release gate

Register does not publish a production actor merely because its unit tests pass. Every release that
can activate ActivityPub must carry an exact-version peer-matrix report and a hash-linked attestation.
Without both files, activation fails closed while installation and private setup remain available.

This is a release-engineering gate, not an operator checkbox. Never copy a previous attestation,
replace a version string, or claim a scenario that was not observed against the archived build.

## Required artifacts

The release must contain these regular, non-symlink files:

```text
_extensions/activitypub/resources/interoperability-results.json
_extensions/activitypub/resources/interoperability-attestation.json
```

The repository contains `.dist` templates only. The results file is limited to 1 MiB. The
attestation's `suite_sha256` must equal the SHA-256 of the exact result-file bytes included in the
release. Reformatting or adding a newline after hashing invalidates the gate.

The gate also requires:

- the exact `Manifest::VERSION` and protocol profile;
- one common RFC 3339 completion timestamp in both files;
- SQLite, MySQL/MariaDB, and PostgreSQL database profiles;
- a run made under the supported shared-hosting constraints: no Redis, no external cron, and no
  `ext-openssl` dependency;
- exactly one result for every required peer family;
- every required scenario in every peer result.

The check can be run locally with:

```bash
php tools/check-activitypub-interoperability.php
```

It exits non-zero and prints the same blocking reason used by the activation wizard.

## Required peer families

The profile-v1 matrix is:

| Family key | Test target |
|---|---|
| `register` | A second isolated Register installation from the exact release build |
| `mastodon` | A supported Mastodon release |
| `gotosocial` | A supported GoToSocial release |
| `akkoma` | A supported Akkoma release |
| `misskey` | A supported Misskey or explicitly recorded Sharkey-compatible target |
| `wordpress-activitypub` | WordPress with the exact ActivityPub plugin version |
| `ghost` | A Ghost build with its exact ActivityPub implementation version |
| `writefreely` | A WriteFreely build with federation enabled |

Record the exact tested application/plugin version in `implementation_version`. Do not write
`latest`, a floating container tag, or a family-only label. If a target is patched, record enough
version/build identity to reproduce that patch outside the result JSON.

## Required scenarios

Each peer entry must contain this complete set:

| Scenario key | Minimum evidence |
|---|---|
| `discovery` | WebFinger resolves the immutable actor; actor/key/collections parse in both directions |
| `follow` | Follow, Accept or Reject, Undo, and shared-inbox selection are observed without duplicate relationships |
| `create` | Public and Unlisted post delivery preserves author, canonical URL, recipients, HTML, tags, mentions, and bounded attachments |
| `update` | The same object ID receives a changed immutable activity/snapshot and ownership is enforced |
| `delete` | Delete is accepted and the old object dereferences as Tombstone; republish creates a new incarnation |
| `reply` | A local authored Note and a remote reply round-trip through the intended moderation/privacy path |
| `like` | Like is accepted, attributed, stored individually, and reflected in the intended aggregate |
| `announce` | Author/collective Announce and reader Announce are accepted without changing object ownership |
| `undo` | Exact original Follow/Like/EmojiReact/Announce relationships are undone idempotently |
| `signed_fetch` | Actor/object retrieval works when the target requires a freshly signed GET |
| `retry` | Redirect, one auth refresh, Retry-After, temporary backoff, and permanent failure classification are observed without sleeping |
| `duplicate_delivery` | Replayed activity bytes do not duplicate side effects; conflicting bytes for one activity ID fail safely |

A parser accepting a POST is not enough. Inspect the peer-visible result and Register's durable
state. If an implementation genuinely cannot express a required scenario, the release gate remains
closed until the product profile or supported matrix is explicitly revised; do not encode
`not_applicable` as a pass.

## Test topology

Use disposable, publicly reachable test origins with valid TLS and isolated databases. At minimum:

- two Register origins, so collective/author actors and both directions can be tested without a
  third-party implementation;
- one isolated peer origin per implementation family or a resettable target with an exact snapshot;
- public DNS whose answers do not include private, loopback, link-local, documentation, or mixed
  addresses;
- a traffic/evidence collector outside the PHP process that records timestamps, status, headers
  after secret redaction, canonical request-body hashes, and peer-visible state;
- clocks synchronized closely enough for the five-minute signature window;
- no production followers, accounts, domains, keys, cookies, or database copies.

Use unique actor handles and origins for every run. Do not recycle a failed public actor URL in a
later supposedly clean run; immutable identifiers and remote caches make that evidence ambiguous.

The peer may run in containers or managed infrastructure, but Register itself must additionally be
tested from its built shared-hosting artifact under request-driven execution. Do not substitute a
continuously draining CLI worker for shutdown generations in the attested run.

## Execution procedure

1. Freeze the candidate commit, lockfile, module version, and protocol profile.
2. Build the shared-hosting distribution and verify that `_vendor/phpseclib` and ActivityPub assets
   are present.
3. Run unit, integration, security, and static checks. Run integration installation/migration tests
   against SQLite, MySQL/MariaDB, and PostgreSQL.
4. Run the pure-PHP RSA suite with the OpenSSL extension unavailable to the tested PHP process.
   HTTPS may still use libcurl TLS.
5. Create fresh test origins and peers. Record exact versions before exchanging an actor URL.
6. For each peer family, execute every scenario in both meaningful directions. Preserve canonical
   request/response body hashes and durable-state observations in the private evidence archive.
7. Resolve every unexplained warning, retry, rejected signature, ownership discrepancy, recipient
   leak, duplicate, or manual database repair. A test that required hand-editing state did not pass.
8. Populate `interoperability-results.json` from its `.dist` template using only passed observations.
9. Set one completion timestamp, compute SHA-256 from the final result bytes, and populate
   `interoperability-attestation.json` from its `.dist` template.
10. Run `php tools/check-activitypub-interoperability.php` from the exact staged release tree.
11. Rebuild the distributable artifact, extract it elsewhere, and run the check again. This catches
    packaging omissions or line-ending transformations.
12. Retain the private raw evidence under release retention policy. The public summary must contain
    no private keys, authorization values, cookies, direct-message bodies, private recipients, or
    real user data.

## Result format

`interoperability-results.json` uses this strict shape:

```json
{
  "schema": 1,
  "module_version": "0.1.0",
  "protocol_profile": "register-activitypub-v1",
  "completed_at": "2026-08-22T12:00:00Z",
  "database_profiles": ["mysql", "pgsql", "sqlite"],
  "runtime": {
    "shared_hosting": true,
    "redis": false,
    "external_cron": false,
    "ext_openssl": false
  },
  "peers": [
    {
      "family": "register",
      "implementation_version": "0.1.0+candidate-sha",
      "scenarios": [
        "announce", "create", "delete", "discovery", "duplicate_delivery", "follow",
        "like", "reply", "retry", "signed_fetch", "undo", "update"
      ]
    }
  ]
}
```

The abbreviated example has one peer for readability; a valid file must contain all eight. Unknown
top-level, runtime, or peer keys are rejected so that unreviewed semantics cannot hide inside a
nominally valid attestation.

`interoperability-attestation.json` contains:

```json
{
  "schema": 1,
  "module_version": "0.1.0",
  "protocol_profile": "register-activitypub-v1",
  "suite_sha256": "64-lowercase-hex-characters",
  "completed_at": "2026-08-22T12:00:00Z",
  "peers": [
    "akkoma", "ghost", "gotosocial", "mastodon", "misskey", "register",
    "wordpress-activitypub", "writefreely"
  ]
}
```

The gate ignores JSON key order but rejects missing/extra keys, wrong types, duplicate or missing
families, incomplete scenarios, another module version/profile, a malformed date, a symlink, an
oversized result, or a hash mismatch.

## Compatibility observations

Do not hardcode folklore about a peer family into the report. During each exact-version run, record
observable differences such as:

- legacy Signature versus RFC 9421 acceptance;
- signed-fetch requirements;
- whether `Article`, `Page`, and Mention tags display as intended;
- shared-inbox behavior and recipient deduplication;
- treatment of Unlisted addressing;
- maximum accepted HTML, attachment, and collection sizes;
- redirect and authorization-refresh behavior;
- whether an accepted activity is visibly applied, silently stored, or later rejected.

An implementation-specific compatibility fallback belongs in reviewed code with a bounded trigger
and regression test. It must not weaken ownership checks, digest coverage, DNS pinning, private-
recipient handling, or the immutable identifier profile.

## When the gate must be renewed

Generate a fresh report and attestation when any of these changes:

- `Manifest::VERSION` or the protocol profile;
- identifier, addressing, visibility, object, or actor behavior;
- signature generation/verification, key handling, or remote fetch policy;
- inbox authorization, sanitizer, delivery classification, retry, or deduplication behavior;
- phpseclib major version or the RSA adapter;
- a required peer family or scenario;
- the packaged shared-hosting runtime in a way that can affect federation.

Routine documentation-only releases may reuse no attestation automatically: the exact module
version check decides. If the version changes, rerun or make an explicit reviewed release-policy
decision rather than editing the gate.
