# Production deployment

Register's recommended shared-hosting layout keeps the application above the document root. A
repository-root deployment remains available when the hosting account cannot provide a private
sibling directory. Apache is the primary shared-hosting target; Nginx is supported when the operator
can edit the virtual-host configuration. In every layout the web server must expose only the reviewed
PHP entrypoints and public static data.

## Recommended split-root package

Build a ready-to-upload archive on a trusted development machine:

```bash
composer build:shared-hosting
```

The resulting `dist/register-shared-hosting.zip` contains a private `register-app/` directory and a
minimal `public_html/`. The build installs the locked production dependencies without development
packages, fails if an unexpected executable file reaches the document root, and prints a SHA-256
checksum. Composer is not needed on the hosting account.

Place `register-app` beside the host's `public_html`, `www`, or `htdocs` directory. Public front
controllers set the private application root explicitly; uploaded images and generated browser
bundles are written below the actual document root. See
[`shared-hosting.md`](shared-hosting.md) for installation, permission, boundary-verification, and
safe-update instructions.

Dynamic AI and Akismet API keys are not stored in the database. In the split-root layout Register
writes them to `register-app/config.secrets.php` with mode `0600`; generated caches and database
backups contain only a marker. Preserve this file separately when moving or restoring a site. The
file can be moved to another private path with `security.secret_file` in `config.php`; relative paths
are resolved from the application root.

## Apache shared hosting

For a repository-root deployment, the checked-in [root `.htaccess`](../.htaccess) is part of the
security boundary. It requires Apache 2.4 with `mod_rewrite`, `AllowOverride All`, and permission to
use `Options`. The same file remains useful in split-root packages for routing and defense in depth.
It provides these rules:

- only `index.php` and the four `_admin/*.php` front controllers can execute directly;
- source, tests, tools, configuration, database, logs, private cache entries, and dependency
  metadata are denied;
- the only public Composer files are AdminYard's exact `demo/style.css` and `demo/script.js` assets;
- only generated top-level `_cache/<name>.<hex>.css|js[.gz]` bundles are public;
- upload directories disable CGI/PHP handlers and deny active document formats.

For this legacy layout, Register first tries a stable `register-secrets-<installation-id>.php` file
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
| generated `_cache/*.css`, `_cache/*.js`, public uploads and thumbnails | `0644` | web server must serve them as data |
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

## HTTPS

Set the canonical base URL to `https://` and enable forced admin HTTPS after the certificate works.
Admin cookies then carry `Secure`, `HttpOnly`, and `SameSite=Strict`; public comment identity uses
`SameSite=Lax`. Enable HSTS only after every required hostname and subdomain is permanently HTTPS.
