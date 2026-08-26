# Production deployment

Register's supported shared-hosting layout is deliberately self-contained: the application,
dependencies, entrypoints, and runtime directories all live in the hosting provider's document root.
It does not require PHP to include files above `public_html`, `www`, or `htdocs`. Apache is the
primary shared-hosting target; Nginx is supported when the operator can edit the virtual-host
configuration. The web server must expose only the reviewed PHP entrypoints and public static data.

## Single-root package

Build a ready-to-upload archive on a trusted development machine:

```bash
composer build:shared-hosting
```

The resulting `dist/register-shared-hosting.zip` contains one complete `public_html/` tree plus
operator documentation. The build installs the locked production dependencies without development
packages, verifies the exact front controllers and Apache policy, rejects active files in data
directories, and prints a SHA-256 checksum. Composer is not needed on the hosting account.

Copy the contents of the packaged `public_html/` into the host's `public_html`, `www`, or `htdocs`
directory. Do not create a `register-app` sibling and do not rewrite the front controllers. See
[`shared-hosting.md`](shared-hosting.md) for installation, permission, boundary-verification, and
safe-update instructions.

Dynamic AI and Akismet API keys and the antispam/anonymous-visitor HMAC secrets are not stored in the
database. Register stores them in a mode-`0600` PHP file; on ordinary shared hosting the verified
in-root fallback is `config.secrets.php`, which the supplied Apache policy denies. Generated caches
and database backups contain only a marker. Preserve this file separately when moving or restoring
a site. It can be moved to another private path with `security.secret_file` in `config.php` when the
hosting account permits that path. Relative paths are resolved from the application root. See the
[secret-rotation runbook](secret-rotation.md) before replacing any value.

## Apache shared hosting

The checked-in [root `.htaccess`](../.htaccess) is the security boundary for the single-root package.
It requires Apache 2.4 with `mod_rewrite`, `AllowOverride All`, and permission to use `Options`. It
provides these rules:

- only `index.php` and the four `_admin/*.php` front controllers can execute directly;
- source, tests, tools, configuration, database, logs, private cache entries, and dependency
  metadata are denied;
- the only public Composer files are AdminYard's exact `demo/style.css` and `demo/script.js` assets;
- only generated top-level `_cache/<name>.<hex>.css|js[.br|.gz|.zst]` bundles are public;
- upload directories disable CGI/PHP handlers and deny active document formats.
- when `mod_headers` is available, `nosniff`, the referrer policy, and the camera/microphone/location
  restrictions apply to dynamic responses, static assets, uploads, and access-denied responses.

Register first tries a stable `register-secrets-<installation-id>.php` file
beside the document root. If PHP cannot write there, the installer falls back to
`config.secrets.php` inside the application root only after a same-host, IP-pinned HTTP probe proves
that the file's PHP source cannot be downloaded. The fallback file is mode `0600` and the supplied
Apache policy denies it through the general active-file boundary. Installation stops if the probe
cannot establish that boundary; do not bypass it by making the file world-readable.

The full quality gate starts an isolated real Apache process and makes allow/deny requests against
these rules. It can also be run alone:

```bash
REGISTER_REQUIRE_APACHE_SECURITY_TEST=1 composer test:apache-security
```

After deploying, repeat the boundary check against the real hostname. A protected path may return
`403` or `404`, but never `200`:

```bash
curl --fail-with-body --head https://example.com/composer.lock
curl --fail-with-body --head https://example.com/_cache/cache_config.php
curl --fail-with-body --head https://example.com/_include/common.php
```

If any command succeeds, stop the deployment and fix `AllowOverride`/`mod_rewrite`; do not continue
installation with a publicly readable configuration boundary.

Generated CSS/JavaScript bundles receive ready gzip sidecars whenever PHP has zlib. Brotli and Zstd
sidecars are generated too when their PHP extensions are installed. On a host with shell access,
the operator can prepare every available variant after warming the public pages without doing that
work in an HTTP request:

```bash
php tools/precompress-assets.php
```

The command uses native PHP encoders first and then optional `brotli`, `zstd`, or `gzip` executables.
Apache serves these files through the bundled `_cache/.htaccess` rules and falls back to the original
asset when the browser or server lacks a codec. Dynamic responses independently negotiate the same
codecs in PHP; deterministic page-cache responses reuse a content-addressed encoded representation.
Changing a page changes that key immediately, while expired representations are collected by the
normal cache backend.

## Nginx

Nginx does not read `.htaccess`. Start from
[`_doc/examples/nginx-register.conf`](examples/nginx-register.conf), replace the root, PHP socket,
and hostname, then validate the effective configuration with `nginx -t`. The example mirrors the
Apache allow-list rather than relying on a broad `location ~ \.php$` rule.

## Filesystem permissions

Use the hosting account's PHP owner/group; Register does not require a dedicated Unix user.
Recommended modes are:

| Path | Mode | Reason |
|---|---:|---|
| `config.php`, `config.secrets.php`, SQLite database and sidecars | `0600` | contain credentials, backup key, hashes, drafts, and private content |
| private cache metadata and encrypted backup archives | `0600` | contain private state even though backups are encrypted at rest |
| `_cache/` | `0750` | writable private application state |
| private backup directories | `0700` | encrypted archives and plaintext work files are accessible only to the PHP account |
| generated `_cache/*.css`, `_cache/*.js`, their `.br`/`.gz`/`.zst` variants, public uploads and thumbnails | `0644` | web server must serve them as data |
| `_pictures/` and its public subdirectories | `0755` | web server must traverse and read uploads |

Do not use `0777`. Register sets safe modes after writing sensitive files, but ownership and parent
directory traversal still depend on the hosting account.

## Upload storage quota

Register limits the total size of regular files stored under `_pictures/` to 1 GiB by default. The
check runs under an exclusive filesystem lock, so parallel uploads cannot independently consume the
same remaining capacity. Symbolic links are not followed while calculating usage.

Set `files.upload_quota_bytes` in `config.php` to a byte value of at least 200 MiB when the hosting
plan has a different storage budget. Leave enough free account space for the database, cache, logs,
temporary upload copies, and backups; this quota protects the upload directory, not the whole hosting
account.

```php
'files' => [
    'upload_quota_bytes' => 2 * 1024 * 1024 * 1024,
],
```

## Isolated upload storage

When the hosting control panel can map a separate HTTPS hostname to its own directory, keep uploads
outside the main document root and expose that directory only through the media hostname. Configure
the filesystem location and its public URL independently:

```php
'files' => [
    'image_dir' => '/home/account/register-media',
    'image_url' => 'https://media.example.com',
],
```

The external URL must use HTTPS and cannot contain credentials, a query string, or a fragment. Copy
the restrictive `_pictures/.htaccess` and `index.html` files into the media directory, disable script
execution for the media virtual host, and do not configure that hostname to receive application
cookies. Register's own cookies are host-only, so they are not sent to a sibling media hostname.
Existing installations that omit these options continue to use public `_pictures/`.

## HTTPS

Set the canonical base URL to `https://` and enable forced admin HTTPS after the certificate works.
Admin cookies then carry `Secure`, `HttpOnly`, and `SameSite=Strict`; public comment identity uses
`SameSite=Lax`. Enable HSTS only after every required hostname and subdomain is permanently HTTPS.
