# Changelog

## 2.0.0-rc.1 — unreleased

Register 2.0 turns the inherited S2 codebase into a self-hosted, public-first blog engine with
in-place publishing and a release package designed for ordinary shared hosting.

### Highlights

- Public in-place creation, editing, deletion, dates, tags, media, captions, keyboard shortcuts,
  local draft recovery, and optional AI tools.
- Empty excerpts and meta descriptions are completed on save with a local sentence-aware summary or
  an opt-in AI pass; Settings automatically verifies the configured AI provider.
- Partial page navigation, request-driven live updates, same-origin offline fallback, local search,
  reactions, rich comments, and accessible media players.
- Public accounts through existing Register credentials, optional email links, VK ID, Mail.ru,
  Odnoklassniki, and Yandex, with relevant unread-comment counters.
- Split-root shared-hosting archives, encrypted backups, a staged control-panel updater, and
  explicit database migrations from schema generations 15 through 19 to generation 20.
- Comment email addresses are now unconditionally private. The obsolete public-email flags are
  removed during the generation-20 migration, and discussion subscriptions now control both email
  delivery and the authenticated user's unread-comment counter.
- Link health rechecks `404`/`410` targets monthly, including confirmed broken links, so a restored
  page returns to healthy status without an administrator having to notice it first.
- Link inventory recognizes the configured canonical host during local runs, preserves Unicode and
  IDN links, and reuses completed Wayback results instead of repeating imported lookups. Wayback
  jobs now back off together when the archive API is unavailable or rate-limited.
- CI-gated edge builds and manually promoted release-candidate or stable archives.

### Deployment notes

- Install the first updater-capable build with the [staged deployment procedure](_doc/deployment.md).
  Later compatible releases can be installed under **System → Software update**.
- Older databases below generation 15 are not modified automatically. Recreate the installation or
  import the data explicitly.
- Email-link sign-in is disabled on new sites. Enable it only after confirming that production PHP
  mail reaches its recipient.
- OAuth buttons stay hidden until their application credentials are configured.

### Known limitation

- ActivityPub remains optional and inactive by default. A production actor cannot be activated from
  an ordinary source or release-candidate build without the exact-version interoperability report
  and attestation described in the [ActivityPub release gate](_doc/activitypub-interoperability.md).
