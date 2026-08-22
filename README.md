# Register

**A compact, self-hosted blog engine for people who want to write, publish, and get out of the way.**

[Русская версия](README.ru.md) · [Documentation](_doc/architecture.md) · [MIT License](LICENSE.md)

Register is an open-source PHP application for personal blogs and small editorial sites. It keeps
posts at the center, supports permanent pages when they are useful, and avoids turning publishing
into a collection of dashboards and page-builder abstractions.

The engine runs on ordinary PHP hosting with SQLite, MySQL/MariaDB, or PostgreSQL. Its public side is
small, responsive, and self-contained: search, typography, formulas, code highlighting, analytics,
and media enhancements run locally rather than depending on third-party front-end services.

The optional first-party ActivityPub extension adds fediverse actors, publishing, delivery, inbox
processing, a private reader, and moderation without replacing the existing website. HTML pages,
canonical URLs, the editor, local comments and reactions, search, and RSS continue to work normally
with federation disabled, installed but inactive, paused, or active.

Background work advances in short, leased slices registered with `register_shutdown_function` after
normal HTTP responses. Normal operation assumes no Redis, worker daemon, cron entry, or production PHP CLI:
unfinished work is stored in the configured PDO database as a future queue generation instead of
sleeping inside a request. A site without incoming traffic simply waits for its next request.
External-link DNS uses non-blocking datagrams to the system resolvers from `/etc/resolv.conf`; it
never starts a process or calls the potentially blocking libc resolver.

> **Development status:** the current line is `2.0dev`. Register deliberately supports a fresh
> current schema while the product is pre-release; in-place migration from older S2/Register data
> generations is not yet a compatibility promise.

## Why Register

- **Writing first.** Posts, drafts, preview, publishing, tags, and comments are the primary workflow.
- **Short canonical URLs.** The blog lives at `/`, while posts use addresses such as `/post-name`.
- **Useful defaults.** Search, RSS, sitemaps, comments, typography, analytics, and backups are ready
  after installation.
- **A website first, federation when you want it.** ActivityPub is an optional, explicitly activated
  representation of the same blog rather than a replacement publishing system.
- **Private by default.** Core reader features are served locally, and analytics do not store raw IP
  addresses or User-Agent strings.
- **Friendly to ordinary hosting.** A split-root archive is ready to upload without Composer on the
  hosting account, and background work needs neither a daemon nor a cron entry.
- **Extensible without hollowing out the core.** Product features are mandatory base modules;
  integrations and specialized behavior can be optional modules.

## Features

### Publishing and editor

- Blog posts and permanent pages with drafts, revisions, backdating, scheduled publication, and
  explicit publish/unpublish controls.
- Authorized authors can create, edit, and delete posts directly on the public blog. The in-place
  and control-panel editors share media upload, live preview, keyboard shortcuts, native undo/redo,
  optimistic revision checks, and article-body recovery from `localStorage` after navigation, a tab
  closure, or a browser crash.
- Optional AI assistance through your own Gemini, Groq, OpenRouter, Mistral, Cloudflare Workers AI,
  Yandex AI Studio, or GigaChat credentials:
  fix spelling, grammar, punctuation, and typos; improve or shorten text; suggest a title; and
  suggest tags.
- AI results are applied directly to the editor, changed fragments are marked, and every edit remains
  reversible through the normal undo/redo history. API keys are stored on the server, and Settings
  includes step-by-step instructions for creating one.
- With a vision-capable model, uploaded images receive concise alt text automatically. The setting is
  on by default when available, can be switched off manually, and generated alt text can be edited,
  regenerated, undone, or redone directly in the editor.
- Tag entry with reusable suggestions, keyboard navigation, deduplication, and selected tags rendered
  as removable tokens instead of a comma-filled text box.
- Image, audio, and video uploads, thumbnails, clipboard image insertion, tracked media usage, a live
  media preview, and editable captions placed over images.
- Multiple users with separate author, moderator, editor, and administrator permissions.

AI support is disabled by default. Provider availability, free quotas, billing, and usage limits are
governed by each external service rather than by Register. Settings contains provider-specific setup
steps, including the Cloudflare Account ID, the Yandex Cloud folder ID, and the GigaChat access scope
and certificate guide. When its Model field is empty, OpenRouter uses the free-model router, Mistral
uses `mistral-small-latest`, and Cloudflare uses a multilingual Workers AI model.

### Reading and discovery

- Tags, favorites, calendar archives, pagination, a compact all-posts index, and related-content
  recommendations.
- Full-text search across posts and pages with English stemming and Russian OpenCorpora morphology.
  Known Russian words are indexed by dictionary lemmas; unknown words fall back to the existing
  Russian/English Porter chain.
- Automatic search-index creation during installation and resumable health repair through the
  background queue.
- One canonical RSS feed at `/rss`; the historical `/rss.xml` address permanently redirects for
  existing subscriptions. Register also serves `/robots.txt` and a standards-compliant
  `/sitemap.xml`. Large sitemaps are split into conservative 10,000-URL files and contain only
  published canonical content.
- Locale-aware typography, locally rendered KaTeX formulas, and lazy local Highlight.js syntax
  highlighting for 44 programming languages.
- A lightweight accessible audio player that progressively enhances native `<audio>` and is loaded
  only when a page needs it.
- Progressive partial navigation and a conservative same-origin offline cache keep ordinary links
  usable while preserving complete server-rendered pages as the baseline.

### Optional ActivityPub federation

- A first-party optional extension with a collective `Service` or `Organization` actor and
  author-owned `Person` actors. Handles are independent from administrator login names.
- WebFinger, NodeInfo, actor/object/activity/key documents, inboxes, outboxes, followers, following,
  featured collections, shared-inbox delivery, signed fetches, and immutable public identifiers.
- Per-site defaults and per-item overrides for posts and pages: opt in or out, `Article` or compatible
  `Note`, full portable HTML or excerpt plus canonical link, Public or Unlisted visibility, content
  warning, and language. The editor can preview the exact ActivityStreams JSON before saving.
- Follow, reply, Like, EmojiReact, Announce, Undo, Update, Delete, and Move workflows. Remote replies
  enter the normal Register comment and moderation path; remote avatars are fetched after the
  response, validated, mirrored, and served from the local origin.
- A private per-author reader for following remote actors and sending authenticated replies,
  reactions, and reposts without changing the public blog interface.
- Pure-PHP RSA signatures through phpseclib and Sodium-encrypted private keys. ActivityPub does not
  require `ext-openssl`, Redis, Node.js, an external cron entry, or a continuously running worker.
- Installation creates private storage but publishes nothing. Activation is a separate verified
  operation that freezes the canonical HTTPS identity only after routing, cryptography, signed inbox,
  and release-interoperability checks pass. Raw source checkouts contain `.dist` interoperability
  templates; a production-capable release must carry genuine hash-linked peer-matrix results.

See [ActivityPub operations](_doc/activitypub-operations.md) for setup and the exact shared-hosting
contract.

### Themes and presentation

Register ships with four first-party themes:

- **Register** — the default restrained editorial theme with system light/dark appearance;
- **Oldschool** — a dense, discussion-oriented layout with Slashdot-style threaded comments;
- **Pixel Forest** — a playful pixel-art reading theme;
- **System 1** — a monochrome 1984 Macintosh-inspired theme using grayscale macOS system artwork.

Themes, templates, views, CSS, and module resources can be overridden without changing the
publishing model.

### Comments, reactions, and moderation

- Threaded comments with a rich editor, sanitized canonical HTML storage, preview, moderation,
  subscriptions, email notifications, and private commenter email addresses.
- Inline moderation in the control panel, important-comment marking, and author replies.
- Built-in local anti-spam scoring, rate limits, reputation, configurable rules, diagnostics, and
  optional Akismet integration.
- Login-free emoji reactions for posts, pages, and comments, including imported aggregate counts. A
  signed anonymous visitor identity keeps one active reaction per item without storing raw
  fingerprints, IP addresses, or User-Agent strings in visitor tables.

### Operations and security

- SQLite 3.37+, MySQL 8.0+/MariaDB 10.5+, and PostgreSQL 14+.
- A durable, leased, at-least-once queue advanced in a bounded shutdown phase after successful HTTP
  requests. It handles scheduled publication, search maintenance, link checking, thumbnails,
  anti-spam cleanup, ActivityPub delivery and inbox work, and backups without a separate worker
  process.
- Automatic inventory of local and external links, bounded SSRF-safe background checks, visible
  history, deliberate rechecks, Wayback discovery, and revision-safe replacement of confirmed broken
  links. Local references also protect targets from accidental deletion.
- Daily database-and-media backups protected by authenticated encryption, with retention, manual
  creation, control-panel download, offline decryption tools, and optional recipient encryption whose
  private recovery key never reaches the hosting account.
- Password and passkey login, one-use recovery codes, session management, login rate limits,
  reauthentication for sensitive changes, a structured security audit trail, and strict Content
  Security Policy and browser security headers.
- API keys and internal HMAC secrets are kept outside the database. Uploads have a total storage
  quota and may live on an isolated HTTPS media origin.
- A reviewed split-root shared-hosting package keeps PHP source, dependencies, configuration,
  database, private cache, and encrypted backups outside `public_html`; Composer and shell access are
  not required on the hosting account.
- Timestamped production prereleases in ZIP, tar.gz, and tar.bz2, plus a staged control-panel
  updater with per-file hashes, maintenance mode, backups, database migrations, and file rollback.
- Automatic Brotli or gzip response compression when the matching optional PHP extension is
  installed and the browser accepts it.
- Privacy-conscious daily page-view and feed-reader statistics.
- Queue inspection and deliberate retry tools for reviewed failed jobs.

Because background execution is request-driven, a site with no incoming traffic has no guaranteed
delivery time for scheduled or queued work. This trade-off is intentional and keeps deployment
simple.

## Recent development by Evgeny Stepanischev

The Register 2.0 product direction and modernization have been substantially developed by
**Evgeny Stepanischev**. Features authored or extensively reworked by Evgeny in the current tree
include:

- the Register identity, root-blog URL policy, short canonical slugs, unified content model, and
  mandatory base-module architecture;
- the public reading design, redesigned administration, editorial workflow, local draft recovery,
  in-place post creation and editing, rich comments, image captions, AI-assisted proofreading, and
  the token-based tag editor;
- scheduled publishing, the request-driven queue, authenticated encrypted backups, the split-root
  shared-hosting package, and automatic response compression;
- privacy-conscious analytics, local anti-spam controls, anonymous visitor identity, emoji
  reactions, passkeys, security auditing, and deployment hardening;
- the first-party ActivityPub extension, including identity lifecycle, content federation, delivery,
  inbox processing, private reader, moderation, recovery, and shared-hosting operations;
- Russian OpenCorpora search morphology, automatic index repair, canonical RSS, `robots.txt`, and
  split XML sitemaps;
- locale-aware typography, local KaTeX rendering, lazy syntax highlighting, the audio player,
  Oldschool comments, and Pixel Forest.

Register is based on the S2 codebase and reusable infrastructure created by **Roman Parpalak**.
Third-party components retain their own copyright notices and licenses.

## Requirements

- A web server that can route Register's reviewed PHP entrypoints. Apache 2.4 with `mod_rewrite`
  and `AllowOverride All` is the primary shared-hosting target; Nginx needs the supplied equivalent
  allow-list configuration.
- PHP 8.3 or newer. The quality suite checks compatibility with PHP 8.3–8.5.
- Required PHP extensions: DOM, Fileinfo, Filter, GD, JSON, Libxml, PDO, Session, and Sodium.
- One supported database:
  - SQLite 3.37 or newer;
  - MySQL 8.0 or newer, or MariaDB 10.5 or newer;
  - PostgreSQL 14 or newer.
- Optional PHP extensions:
  - Intl for higher-quality ICU URL transliteration;
  - Brotli or zlib for automatic response compression;
  - cURL for HTTP integrations.

ActivityPub signing uses the bundled phpseclib adapter and does not require the PHP OpenSSL
extension. A cURL build with TLS is the supported shared-hosting HTTPS transport when `ext-openssl`
is unavailable.

The control panel targets Chrome/Edge 111+, Firefox 113+ (including ESR 115+), and Safari 16.2+.
SQLite backups require no external database utility. MySQL/MariaDB backups need `proc_open` and
`mysqldump`; PostgreSQL backups need `proc_open` and `pg_dump`.

## Installation

### Shared hosting (recommended)

Build the split-root archive on a trusted computer; Composer is not needed on the hosting account:

```bash
git clone https://github.com/bolknote/Register.git register
cd register
composer install --no-interaction
composer build:shared-hosting
```

Upload both directories from `dist/register-shared-hosting.zip`: keep `register-app/` beside the
hosting provider's `public_html`, `www`, or `htdocs`, and copy the packaged `public_html/` contents
into that document root. Then open `/_admin/install.php` and follow the installer. The application,
configuration, database, private cache, and encrypted backups stay outside the public directory.

See [Shared-hosting installation](_doc/shared-hosting.md) for permissions, safe updates, media-host
isolation, and post-deployment boundary checks.

### Repository-root installation

When the hosting account cannot provide a private sibling directory, install production dependencies
in the checkout. On Apache, the checked-in root `.htaccess` is the security boundary; Nginx must
reproduce its allow-list using the supplied example configuration:

```bash
composer install --no-dev --optimize-autoloader
```

1. Point the web-server document root at the checkout.
2. Allow the PHP user to write to `_cache/` and `_pictures/`.
3. Verify that requests for `composer.lock`, `_include/common.php`, and private cache files cannot
   return `200`.
4. Open `/_admin/install.php`, choose the database, and create the administrator account.

After installation, open the control panel from the small Register mark in the public footer. The
starter post explains the first useful actions and can be edited or deleted.

## Local development

Start an isolated SQLite installation in one command:

```bash
./bin/dev
```

The script installs missing Composer dependencies, creates disposable state under `.local/`, prints
the local credentials, and serves Register at `http://127.0.0.1:8080`. It never modifies an existing
production `config.php` or production database.

Useful overrides are `S2_DEV_HOST`, `S2_DEV_PORT`, `PHP_BIN`, `S2_DEV_ADMIN_LOGIN`, and
`S2_DEV_ADMIN_PASSWORD`.

Run the complete quality gate with:

```bash
composer check
```

It rebuilds Codeception support, runs PHP and shell linters, maximum-level static analysis,
dependency checks, Rector in dry-run mode, a real-Apache shared-hosting policy check, and the unit,
integration, and acceptance suites. The Apache check is skipped locally when Apache is unavailable
and is mandatory in CI.

## Background jobs and backups

Register closes the PHP session and sends the response before offering a short time-limited slice to
the queue where the SAPI allows it. Only one shutdown runner owns the database lease at a time.
Failed work uses exponential backoff and becomes visible after the retry limit.

```bash
php tools/queue-status.php
php tools/retry-background-job.php <id> <code>
php tools/backup.php
```

There is intentionally no command that blindly drains or retries the entire queue. See
[Backups](_doc/backups.md) and [Architecture](_doc/architecture.md) for the operational contract.

## Architecture and modules

Register is built on S2:

- `S2\Cms` provides reusable HTTP, dependency injection, events, database access, queues, caching,
  and administration infrastructure.
- `Register` owns posts, pages, comments, tags, search, editorial workflows, analytics, presentation,
  and product policy.

Base modules are part of every installation and cannot be disabled. Optional modules live under
`_extensions/` and may add services, listeners, routes, administration pages, translations,
templates, views, and assets through documented integration boundaries. ActivityPub is such an
optional module: installing it is reversible while inactive, and publishing a permanent federation
identity requires a separate explicit activation lifecycle.

## Documentation

- [Development](_doc/development.md)
- [Production deployment](_doc/deployment.md)
- [Shared-hosting package](_doc/shared-hosting.md)
- [Architecture](_doc/architecture.md)
- [Assets and loading policy](_doc/assets.md)
- [URL and slug generation](_doc/url-slugs.md)
- [Backups and restore](_doc/backups.md)
- [Secret rotation and incident recovery](_doc/secret-rotation.md)
- [Comments](_doc/comments.md)
- [Anonymous identity and reactions](_doc/anonymous-identity-and-reactions.md)
- [Audio player](_doc/audio-player.md)
- [Code highlighting](_doc/code-highlighting.md)
- [Optional modules](_doc/extensions.md)
- [ActivityPub protocol profile](_doc/activitypub-protocol-profile.md)
- [ActivityPub shared-hosting operations](_doc/activitypub-operations.md)
- [ActivityPub interoperability gate](_doc/activitypub-interoperability.md)
- [Dependency policy](_doc/dependencies.md)
- [Register and Aegea comparison](_doc/egea-comparison.md)
- [Architecture decision: module tiers](_doc/decisions/0001-register-module-tiers.md)
- [Architecture decision: ActivityPub federation](_doc/decisions/0002-activitypub-federation.md)

## License

Register is free software released under the [MIT License](LICENSE.md).
