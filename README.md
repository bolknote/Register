# Register — a small, fast blog engine

Register is an open-source PHP engine for personal blogs and compact editorial sites, built on S2.
S2 supplies the reusable HTTP, dependency-injection, event, database, queue, and administration
infrastructure. Register owns the publishing model, editorial workflow, public theme, control-panel
experience, and URL policy.

Register is designed for writing and publishing without turning the site into a collection of
dashboards, widgets, and unnecessary abstractions. Blog posts are the primary content type;
arbitrary permanent pages remain available for material such as an about page or project
documentation.

Register is free software distributed under the MIT license. It runs on an ordinary PHP host and can
use SQLite, MySQL/MariaDB, or PostgreSQL.

## What Register already does

- Publishes blog posts and permanent pages, including drafts and scheduled dates.
- Gives every post a short permalink such as `/post-name`; the blog lives at `/`.
- Organizes material with tags, sections, archives, favorites, RSS, and a sitemap.
- Supports comments, moderation, subscriptions, and spam filtering.
- Provides accounts and permissions for authors, moderators, editors, and administrators.
- Recovers unsaved editor text after a browser or power failure.
- Handles image uploads and thumbnails and provides a module API for additional integrations.
- Keeps the public theme deliberately small, responsive, readable, and compatible with light and
  dark system appearance.

Blog, permanent pages, comments, tags, search, typography, analytics, formula rendering, and the
control panel are base modules. They ship with Register, are always available, and cannot be disabled
or uninstalled. Optional modules remain available for integrations and specialized behavior.

The project follows the 80/20 principle: the everyday publishing path should be excellent. Register
is a blog engine, not a universal site builder.

## First launch

A fresh installation opens with a welcome note that explains the engine and points to the first
useful actions. Edit or delete that note, publish the first post, choose the site name, and the blog
is ready. Built-in search is indexed during installation; no module installation or initial rebuild
is required. The unobtrusive lock in the public footer opens the control panel.

## Requirements

- A web server.
- PHP 8.3 or newer. The codebase is continuously checked for PHP 8.3–8.5 compatibility.
- PHP extensions: DOM, Filter, GD, JSON, PDO, and Session. Intl improves URL transliteration;
  cURL and zlib are also optional. Register does not require iconv.
- One supported database:
  - MariaDB 10.5+ or MySQL 8.0+;
  - PostgreSQL 14+;
  - SQLite 3.37+.

The control panel targets Chrome/Edge 111+, Firefox 113+ (ESR 115+), and Safari 16.2+.

## Installation

```bash
git clone https://github.com/bolknote/Register.git register
cd register
composer install --no-dev -o
```

See the [installation documentation](https://github.com/parpalak/s2/wiki/Installation) for web-server
and database setup.

## Local development in one command

```bash
./dev
```

The command installs missing Composer dependencies, creates an isolated SQLite site in `.local/`,
serves it at `http://127.0.0.1:8080`, and runs the local queue worker so search and thumbnails update
automatically. On first launch it prints the local credentials. Existing `config.php` and production
data are never modified. An incompatible local schema is discarded and recreated because Register
does not migrate pre-release data generations. Override the host, port, PHP executable, or initial
credentials with `S2_DEV_HOST`, `S2_DEV_PORT`, `PHP_BIN`, `S2_DEV_ADMIN_LOGIN`, and
`S2_DEV_ADMIN_PASSWORD`.

Run maximum-level static analysis, compatibility checks, linters, and tests with:

```bash
composer check
```

## Documentation

- [Development](_doc/development.md)
- [Architecture](_doc/architecture.md)
- [URL slug generation](_doc/url-slugs.md)
- [Comments](_doc/comments.md)
- [Register and Aegea feature comparison](_doc/egea-comparison.md)
- [Optional modules](_doc/extensions.md)
- [Architecture decision: module tiers](_doc/decisions/0001-register-module-tiers.md)
- [Control panel](https://github.com/parpalak/s2/wiki/Control-Panel)
- [Styles](https://github.com/parpalak/s2/wiki/Styles)

## Compatibility note

Register is based on S2. Reusable foundation code continues to use the `S2\Cms` namespace while
Register-owned product code moves to the `Register` namespace. Existing `S2_*` configuration keys,
extension directory names, and selected environment variables are transitional implementation
identifiers, not a compatibility promise or the product name shown to readers and administrators.
