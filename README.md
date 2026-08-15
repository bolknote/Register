# Register

**A compact, self-hosted blog engine for people who want to write, publish, and get out of the way.**

[Русская версия](README.ru.md) · [Documentation](_doc/architecture.md) · [MIT License](LICENSE.md)

Register is an open-source PHP application for personal blogs and small editorial sites. It keeps
posts at the center, supports permanent pages when they are useful, and avoids turning publishing
into a collection of dashboards and page-builder abstractions.

The engine runs on ordinary PHP hosting with SQLite, MySQL/MariaDB, or PostgreSQL. Its public side is
small, responsive, and self-contained: search, typography, formulas, code highlighting, analytics,
and media enhancements run locally rather than depending on third-party front-end services.

Background work is advanced in short, leased `register_shutdown` slices after normal HTTP responses.
Normal operation assumes neither cron nor PHP CLI: unfinished network work is persisted as a future
queue generation instead of sleeping inside a request. A site without incoming traffic simply does
not advance maintenance until its next request. External-link DNS uses non-blocking datagrams to the
system resolvers from `/etc/resolv.conf`; it never starts a process or calls the potentially blocking
libc resolver.

> **Development status:** the current line is `2.0dev`. Register deliberately supports a fresh
> current schema while the product is pre-release; in-place migration from older S2/Register data
> generations is not yet a compatibility promise.

## Why Register

- **Writing first.** Posts, drafts, preview, publishing, tags, and comments are the primary workflow.
- **Short canonical URLs.** The blog lives at `/`, while posts use addresses such as `/post-name`.
- **Useful defaults.** Search, RSS, sitemaps, comments, typography, analytics, and backups are ready
  after installation.
- **Private by default.** Core reader features are served locally, and analytics do not store raw IP
  addresses or User-Agent strings.
- **Friendly to ordinary hosting.** Background work advances after normal HTTP responses; Register
  needs neither a daemon nor a cron entry.
- **Extensible without hollowing out the core.** Product features are mandatory base modules;
  integrations and specialized behavior can be optional modules.

## Features

### Publishing and editor

- Blog posts and permanent pages with drafts, revisions, backdating, scheduled publication, and
  explicit publish/unpublish controls.
- A focused editorial screen with live preview, keyboard shortcuts, native undo/redo, and article
  body recovery from `localStorage` after navigation, a tab closure, or a browser crash.
- Optional AI assistance through your own Gemini or Groq API key: fix spelling, grammar, punctuation,
  and typos; improve or shorten text; suggest a title; and suggest tags.
- AI results are applied directly to the editor, changed fragments are marked, and every edit remains
  reversible through the normal undo/redo history. API keys are stored on the server, and Settings
  includes step-by-step instructions for creating one.
- Tag entry with reusable suggestions, keyboard navigation, deduplication, and selected tags rendered
  as removable tokens instead of a comma-filled text box.
- Image, audio, and video uploads, thumbnails, clipboard image insertion, and a live media preview.
- Multiple users with separate author, moderator, editor, and administrator permissions.

AI support is disabled by default. Provider availability, free quotas, and usage limits are governed
by Gemini and Groq rather than by Register.

### Reading and discovery

- Tags, favorites, calendar archives, pagination, a compact all-posts index, and related-content
  recommendations.
- Full-text search across posts and pages with English stemming and Russian OpenCorpora morphology.
  Known Russian words are indexed by dictionary lemmas; unknown words fall back to the existing
  Russian/English Porter chain.
- Automatic search-index creation during installation and resumable health repair through the
  background queue.
- One canonical RSS feed plus `/robots.txt` and a standards-compliant `/sitemap.xml`. Large sitemaps
  are split into conservative 10,000-URL files and contain only published canonical content.
- Locale-aware typography, locally rendered KaTeX formulas, and lazy local Highlight.js syntax
  highlighting for 44 programming languages.
- A lightweight accessible audio player that progressively enhances native `<audio>` and is loaded
  only when a page needs it.

### Themes and presentation

Register ships with three first-party themes:

- **Register** — the default restrained editorial theme with system light/dark appearance;
- **Oldschool** — a dense, discussion-oriented layout with Slashdot-style threaded comments;
- **Pixel Forest** — a playful pixel-art reading theme.

Themes, templates, views, CSS, and module resources can be overridden without changing the
publishing model.

### Comments, reactions, and moderation

- Threaded comments, preview, moderation, subscriptions, email notifications, and private commenter
  email addresses.
- Inline moderation in the control panel, important-comment marking, and author replies.
- Built-in local anti-spam scoring, rate limits, reputation, configurable rules, diagnostics, and
  optional Akismet integration.
- Login-free emoji reactions for posts and pages. A signed anonymous visitor identity keeps one
  active reaction per item without storing raw fingerprints, IP addresses, or User-Agent strings in
  visitor tables.

### Operations

- SQLite 3.37+, MySQL 8.0+/MariaDB 10.5+, and PostgreSQL 14+.
- A durable, leased, at-least-once queue advanced in a bounded shutdown phase after successful HTTP
  requests. It handles scheduled publication, search maintenance, thumbnails, anti-spam cleanup, and
  backups without a separate worker process.
- Private daily database-and-media ZIP backups with retention, manual creation, control-panel
  download, and a command-line backup tool.
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
  AI-assisted proofreading, and token-based tag editor;
- scheduled publishing, the request-driven queue, automatic private backups, and automatic response
  compression;
- privacy-conscious analytics, local anti-spam controls, anonymous visitor identity, and emoji
  reactions;
- Russian OpenCorpora search morphology, automatic index repair, canonical RSS, `robots.txt`, and
  split XML sitemaps;
- locale-aware typography, local KaTeX rendering, lazy syntax highlighting, the audio player,
  Oldschool comments, and Pixel Forest.

Register is based on the S2 codebase and reusable infrastructure created by **Roman Parpalak**.
Third-party components retain their own copyright notices and licenses.

## Requirements

- A web server capable of serving a PHP application from the repository root.
- PHP 8.3 or newer. The quality suite checks compatibility with PHP 8.3–8.5.
- Required PHP extensions: DOM, Filter, GD, JSON, PDO, and Session.
- One supported database:
  - SQLite 3.37 or newer;
  - MySQL 8.0 or newer, or MariaDB 10.5 or newer;
  - PostgreSQL 14 or newer.
- Optional PHP extensions:
  - Intl for higher-quality ICU URL transliteration;
  - Brotli or zlib for automatic response compression;
  - cURL for HTTP integrations.

The control panel targets Chrome/Edge 111+, Firefox 113+ (including ESR 115+), and Safari 16.2+.
SQLite backups require no external database utility. MySQL/MariaDB backups need `proc_open` and
`mysqldump`; PostgreSQL backups need `proc_open` and `pg_dump`.

## Installation

```bash
git clone https://github.com/bolknote/Register.git register
cd register
composer install --no-dev --optimize-autoloader
```

1. Point the web-server document root at the checkout.
2. Allow the PHP user to write to `_cache/` and `_pictures/`.
3. Open `/_admin/install.php` in a browser.
4. Choose the database, create the administrator account, and follow the installer.

After installation, open the control panel from the small Register mark in the public footer. The
starter post explains the first useful actions and can be edited or deleted.

## Local development

Start an isolated SQLite installation in one command:

```bash
./dev
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
dependency checks, Rector in dry-run mode, and the unit, integration, and acceptance suites.

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
templates, views, and assets through documented integration boundaries.

## Documentation

- [Development](_doc/development.md)
- [Architecture](_doc/architecture.md)
- [Assets and loading policy](_doc/assets.md)
- [URL and slug generation](_doc/url-slugs.md)
- [Backups and restore](_doc/backups.md)
- [Comments](_doc/comments.md)
- [Anonymous identity and reactions](_doc/anonymous-identity-and-reactions.md)
- [Audio player](_doc/audio-player.md)
- [Code highlighting](_doc/code-highlighting.md)
- [Optional modules](_doc/extensions.md)
- [Dependency policy](_doc/dependencies.md)
- [Register and Aegea comparison](_doc/egea-comparison.md)
- [Architecture decision: module tiers](_doc/decisions/0001-register-module-tiers.md)

## License

Register is free software released under the [MIT License](LICENSE.md).
