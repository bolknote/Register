# Shared-hosting package

This package keeps the application and its private state outside the document root. It is intended
for hosting accounts where `public_html`, `www`, or `htdocs` has a writable sibling directory.
Neither Composer nor shell access is required on the hosting account.

## Required layout

Upload or extract both package directories without merging them:

```text
/home/account/
├── register-app/        application code, vendor libraries, config, database and private cache
└── public_html/         the web server document root
    ├── index.php
    ├── _admin/          four PHP entrypoints plus public administration assets
    ├── _assets/
    ├── _cache/          generated public CSS and JavaScript only
    ├── _pictures/       public uploads, protected from script execution
    └── _styles/
```

The public entrypoints resolve `register-app` as a sibling of the document root, so the document-root
directory itself may be named `public_html`, `www`, or `htdocs`. Do not place `register-app` inside
the document root and do not point another public subdomain at it.

If the control panel supports a separate HTTPS media hostname, uploads can also be moved to a third
sibling directory. Set `files.image_dir` to its absolute filesystem path and `files.image_url` to the
media origin in `register-app/config.php`. Copy the packaged `_pictures/.htaccess` and `index.html`
into that directory, and point only the media hostname at it. This keeps untrusted files outside the
main document root and prevents Register's host-only cookies from accompanying media requests.

If the hosting plan cannot store application files above the document root, use the repository-root
layout described in `_doc/deployment.md` instead. Its `.htaccess` boundary must be verified before
installation.

## Fresh installation

1. Build the archive on a trusted development machine:

   ```bash
   composer build:shared-hosting
   ```

   The command installs the exact locked production dependencies without development packages,
   writes `dist/register-shared-hosting.zip`, and prints its SHA-256 checksum.
2. Extract the archive in the hosting account's private home directory. If the provider already
   created `public_html`, copy the *contents* of the packaged `public_html` into it and keep
   `register-app` beside it.
3. Keep ordinary code and directories at `0644`/`0755`. `register-app/_cache` should be writable by
   PHP and no broader than `0750`; public `_cache` and `_pictures` normally need `0755` on shared
   hosting. Never use `0777`.
4. Confirm that Apache honors `.htaccess` (`AllowOverride` and `mod_rewrite`) and then open
   `https://example.com/_admin/install.php`. The installer writes `config.php`, SQLite, and private
   cache data under `register-app`, while uploads and generated browser assets go under the real
   document root. AI/Akismet API keys and internal antispam/visitor HMAC secrets are stored in
   `register-app/config.secrets.php` with mode `0600`; the database and generated configuration
   cache contain only an opaque marker.
5. Configure the canonical HTTPS URL. Enable forced administration HTTPS only after the certificate
   works for the final hostname.

## Boundary verification

The only PHP files below the document root must be these five entrypoints:

```text
index.php
_admin/ajax.php
_admin/index.php
_admin/install.php
_admin/pictman.php
```

Requests for implementation or operator data must not return `200`:

```bash
curl --fail-with-body --head https://example.com/_include/common.php
curl --fail-with-body --head https://example.com/composer.lock
curl --fail-with-body --head https://example.com/_pictures/.htaccess
```

The first two paths are absent from `public_html`; the last one verifies that Apache does not serve
its access-control file. Keep `_pictures/.htaccess` in place: it disables executable handlers and
denies active document formats independently of the application's upload validation.

Also inspect a static response with `curl --head https://example.com/_pictures/<existing-image>`.
When Apache provides `mod_headers`, it must include `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, and
`Permissions-Policy: camera=(), microphone=(), geolocation=()`. The application emits the same
headers for dynamic pages, but only the web-server rule can cover static files and denied requests.

## Updating an existing site

Create and verify a backup before replacing code. Preserve all installation-specific state:

- `register-app/config.php` and the configured database;
- `register-app/config.secrets.php` when it exists; it is deliberately excluded from database
  backups and must be transferred through a separate protected channel;
- `public_html/_pictures/` and any configured external media directory;
- private backup archives and any custom paths configured for cache or logs;
- locally installed extensions and styles that are not part of the release.

New backup files end in `.zip.enc`. Preserve `config.php` separately because its generated
`backups.encryption_key` is required to decrypt them; possession of an encrypted archive alone is
not sufficient. See [`backups.md`](backups.md) for the offline decryption command and key-rotation
procedure, and [`secret-rotation.md`](secret-rotation.md) for the complete shared-hosting credential
runbook.

Stage the new package beside the live copy, restore the preserved files into the matching private or
public directory, and only then switch the document root or rename directories. Do not unpack a
release over the live site without a rollback copy. After switching, clear private application cache,
load the public site and administration panel, verify one image and one generated `_cache` asset, and
repeat the boundary checks above.
