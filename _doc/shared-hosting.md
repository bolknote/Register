# Shared-hosting package

This package puts the complete engine, production dependencies, and runtime directories in one
document root. It is intended for ordinary hosting accounts where PHP cannot reliably include files
above `public_html`, `www`, or `htdocs`. Neither Composer nor shell access is required on the hosting
account.

## Required layout

Upload the contents of the packaged `public_html/` into the provider's document root:

```text
/home/account/public_html/    application and web-server document root
├── .htaccess                routing and mandatory access-control policy
├── index.php                public front controller
├── _admin/                  admin front controllers, implementation, templates and assets
├── _assets/
├── _extensions/
├── _include/                application implementation
├── _vendor/                 locked production dependencies
├── _cache/                  protected private state plus allow-listed generated CSS/JavaScript
├── _pictures/               public uploads, protected from script execution
├── _styles/
└── files/                   historical static pages and demos, when present
```

The root directory may be named `public_html`, `www`, or `htdocs`; Register does not depend on that
name. Do not create a `register-app` sibling or modify the packaged front controllers. Because source,
configuration, and private runtime files are physically below the document root, the packaged root
`.htaccess` is a required security boundary rather than an optional optimization.

If the control panel supports a separate HTTPS media hostname, uploads can also be moved to a third
sibling directory. Set `files.image_dir` to its absolute filesystem path and `files.image_url` to the
media origin in `public_html/config.php`. Copy the packaged `_pictures/.htaccess` and `index.html`
into that directory, and point only the media hostname at it. This keeps untrusted files outside the
main document root and prevents Register's host-only cookies from accompanying media requests.

## Fresh installation

1. Build the archive on a trusted development machine:

   ```bash
   composer build:shared-hosting
   ```

   The command installs the exact locked production dependencies without development packages,
   writes `dist/register-shared-hosting.zip`, and prints its SHA-256 checksum.
2. Copy the *contents* of the packaged `public_html/` into the provider-created document root. The
   documentation files beside the packaged directory are for the operator and need not be public.
3. Keep ordinary code and directories at `0644`/`0755`. `_cache` must be writable by PHP and should
   be no broader than the hosting account requires; `_pictures` normally needs `0755` so Apache can
   serve uploads. The root must be writable by the PHP account if browser-based software updates are
   wanted. Never use `0777`.
4. Before entering any credentials, confirm that Apache honors the supplied `.htaccess`
   (`AllowOverride` and `mod_rewrite`) using the checks below. Then open
   `https://example.com/_admin/install.php`. The installer writes `config.php`, SQLite when selected,
   caches, uploads, and generated assets within this one tree. AI/Akismet API keys and internal
   antispam/visitor HMAC secrets are stored in a mode-`0600` PHP file; if a private sibling file is
   unavailable, the installer uses the protected in-root `config.secrets.php` only after an HTTP
   boundary probe. The database and generated configuration cache contain only an opaque marker.
5. Configure the canonical HTTPS URL. Enable forced administration HTTPS only after the certificate
   works for the final hostname.

## Boundary verification

The package contains implementation PHP below the document root, but Apache may route these five
front controllers only:

```text
index.php
_admin/ajax.php
_admin/index.php
_admin/install.php
_admin/pictman.php
```

Direct requests for implementation or operator data must return `403` or `404`, never `200`:

```bash
curl --head https://example.com/_include/common.php
curl --head https://example.com/composer.lock
curl --head https://example.com/config.php
curl --head https://example.com/_cache/cache_config.php
curl --head https://example.com/_pictures/.htaccess
```

These are real or potentially real files in a single-root installation, so a `404` may be a deliberate
rewrite result rather than filesystem absence. Keep the root and `_pictures/.htaccess` files in
place: together they deny source and secrets, disable executable handlers for uploads, and reject
active document formats independently of the application's upload validation. If any protected path
returns `200`, remove the site from service and fix the hosting policy before continuing.

Also inspect a static response with `curl --head https://example.com/_pictures/<existing-image>`.
When Apache provides `mod_headers`, it must include `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, and
`Permissions-Policy: camera=(), microphone=(), geolocation=()`. The application emits the same
headers for dynamic pages, but only the web-server rule can cover static files and denied requests.

## Updating an existing site

After the first updater-capable release has been installed, use **System → Software update** in the
control panel. Download one supported archive from the intended GitHub release; the page identifies
which of `.tar.gz`, `.zip`, and `.tar.bz2` this server can unpack. Register stages and verifies the
whole release, creates an encrypted backup, switches files in maintenance mode, runs database
migrations, and reopens the site only after a final verification. See
[release builds and control-panel updates](self-update.md) for the complete process and recovery
behavior.

Manifest format 2 is the first single-root updater format. A site installed from an older split-root
release must receive one manual single-root deployment before the browser updater can be used. The
same manual procedure applies whenever the updater is unavailable.

Create and verify a backup before replacing code. Preserve all installation-specific state:

- `public_html/config.php` and the configured database;
- `public_html/config.secrets.php` or the configured external secret file when it exists; it is deliberately excluded from database
  backups and must be transferred through a separate protected channel;
- `public_html/_pictures/` and any configured external media directory;
- private backup archives and any custom paths configured for cache or logs;
- locally installed extensions and styles that are not part of the release.

New backup files end in `.zip.enc`. In the default mode, preserve `config.php` separately because
its generated `backups.encryption_key` is required to decrypt them. For stronger isolation, use the
optional public-recipient mode and keep its private recovery configuration entirely off the hosting
account. Possession of an encrypted archive or the public key alone is not sufficient. See
[`backups.md`](backups.md) for setup and offline decryption, and
[`secret-rotation.md`](secret-rotation.md) for the complete shared-hosting credential runbook.

Stage the new packaged `public_html/` beside the live copy, restore the preserved installation state
into the matching paths, and only then switch the document root or rename directories. Do not unpack
a release over the live site without a rollback copy. After switching, clear generated application
cache while retaining logs, backups, update recovery data, and packaged boundary files. Load the
public site and administration panel, verify one image and one generated `_cache` asset, and repeat
the boundary checks above.
