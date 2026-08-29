# Release builds and control-panel updates

Register publishes an `edge` prerelease after every successful push to `main`. The release workflow
waits for Quality, Security analysis, Unit tests, SQLite, MySQL/MariaDB, and PostgreSQL workflows for
the exact commit. A failed or missing required workflow fails the release gate rather than producing
a green no-op run. One GitHub prerelease then contains the same production build in three compression
formats:

- `register-<UTC date-time>-<commit>.zip`;
- `register-<UTC date-time>-<commit>.tar.gz`;
- `register-<UTC date-time>-<commit>.tar.bz2`;
- `SHA256SUMS`, covering the three archive files.

Each archive contains one complete `public_html/` tree, including production Composer dependencies
and `public_html/register-release.json`. A small, hashed `register-build.json` supplies the displayed
version without parsing the nearly one-megabyte file list on every request. The release manifest
uses format 2, identifies the release and database generation range, and records the size, mode, and
SHA-256 digest of every managed file below that one root.
Apart from the manifest itself, every regular archive entry must appear in that file list; an extra
or missing entry makes the archive invalid. The three archives are transport alternatives, not
three separate releases.

## First installation and bootstrap

The browser updater requires the single-root shared-hosting layout: Register's application root and
the web-server document root must be the same directory. It also requires a format-2 installed
`register-release.json`. Therefore, install the first single-root updater-capable release manually
using the staged switch described in the [shared-hosting runbook](shared-hosting.md). This is also the
bootstrap path from the obsolete split-root manifest format. After that, administrators with
user-management permission can use **System → Software update**.

The update page detects the PHP extractors available on the server. Prefer the format marked
**Recommended**. Normally this is `.tar.gz` (Phar + Zlib), with `.zip` (ZipArchive) as the next
choice and `.tar.bz2` (Phar + Bzip2) as another equivalent option.

## Browser update sequence

1. Download one archive from the intended GitHub release and drag it onto the update page.
2. The browser sends 1 MiB chunks, staying below ordinary shared-hosting upload-size limits even
   after multipart form overhead.
3. Register reads the internal manifest, checks the release version and PHP/database requirements,
   extracts into protected `_cache/register-updates` storage, and verifies every file hash.
4. Register compares the live site with the *installed* manifest. It refuses to overwrite an
   unmanaged collision or a managed file changed locally. Missing unmodified files are restored;
   obsolete unmodified files are removed.
5. After the administrator confirms with the current password, Register creates a private encrypted
   database snapshot under the backup directory. Media are not copied: the release manifest never
   manages uploads, and the file rollback journal covers every code file changed by the update.
   Register first stops active requests, so the snapshot is exactly the database state immediately
   before the file switch. A runtime-lock timeout creates no backup; a later retry starts afresh.
6. A maintenance marker stops new public requests with HTTP 503. A process lock waits for active
   requests to finish. Every affected live file is copied into a protected rollback journal under
   the update workspace, then staged files are moved into place atomically where the filesystem
   permits it. A failure during this phase restores the previous files and reopens the site.
7. The browser sends a second request. It runs through the newly installed PHP code, applies any
   database migration chain, clears generated configuration/routes and public CSS/JS caches, and
   verifies all managed live files again. Only then is maintenance mode removed.

If finalization fails, maintenance mode deliberately stays active. The update endpoint remains
available for a retry, including password login at `/_admin/index.php?entity=Update` after an admin
session expires, and the pre-update encrypted backup is retained for manual recovery. Never delete
`.register-maintenance.json` merely to hide a migration error; inspect the application log and
restore the recorded backup when retrying cannot fix the cause.

After a successful finalization, the uploaded archive, staging tree, and file rollback journal are
removed; the encrypted pre-update database snapshot and a small update status record remain. These
snapshots live in the private backup directory's `updates/` subdirectory and follow the configured
backup retention independently from full backups. Abandoned uploads and
non-critical prepared sessions are removed after seven days. Recovery data for a file switch or
failed migration has no automatic expiry.

Finalization preserves deterministic page responses and their prepared gzip, Brotli, and Zstandard
representations. Data mutations already invalidate the affected cache keys transactionally. A code
release selects a new page-cache generation only when `PageCachePoolFactory::CACHE_ABI` is bumped
because cached PHP values or anonymous HTML became incompatible. Hourly maintenance removes at most
128 entries from obsolete generations per pass, avoiding a post-deploy I/O and crawler rewarming
spike.

## Managed and preserved files

The manifest manages only files produced by the release. Runtime state is excluded, including
`config.php`, `config.secrets.php`, databases, private and public caches, uploads in `_pictures`,
backup archives, update workspaces, and maintenance/lock markers. Packaged `.htaccess` and
`index.html` boundary files inside cache and upload directories remain managed; their runtime
contents do not. Files that were never in an installed release are preserved unless an incoming
release needs the exact same path, in which case the update is reported as a conflict.

Database migrations operate on the existing database in place. Stored configuration values are
preserved; defaults introduced by a release are inserted only when the corresponding setting does
not exist. A migration must never recreate or truncate the `config` table.

## Adding a database migration

A release whose schema generation increases must include an idempotent implementation of
`Register\Schema\SchemaMigrationInterface` for each one-generation step and register it with that
interface as its container tag. The release manifest describes the oldest accepted source
generation and the target generation. The updater records each completed step only after its
implementation returns, so an interrupted finalization can retry the same step safely.

Build the three archives locally with:

```bash
composer build:release -- dist/release
```

CI supplies the commit, UTC build timestamp, monotonically increasing build number, and release ID.
Local defaults are intended only for testing the package builder.

## Release candidates and stable releases

Edge builds are development snapshots. To publish a candidate or stable release, open GitHub Actions,
choose **Publish candidate or stable release**, and supply:

- an exact semantic version: `2.0.0-rc.1` for the `rc` channel or `2.0.0` for `stable`;
- the matching channel;
- the commit, tag, or branch to release.

The workflow resolves that reference to one exact commit and refuses to publish unless all six
required push workflows above completed successfully for that commit. It also refuses to replace an
existing version tag. The archives are rebuilt from the verified commit rather than from the workflow
caller’s checkout.

For the same base version, the updater orders channels as `edge` → `rc` → `stable`; it never offers
a move back to an earlier channel. Within one channel, the monotonically increasing build number
orders builds. An operator should first install the release candidate on a production-like copy,
exercise the browser updater and the actual host’s mail/database/media configuration, and only then
publish the stable version.
