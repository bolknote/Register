# Production deployment

Register supports a repository-root deployment on ordinary shared hosting. Apache is the primary
shared-hosting target; Nginx is supported when the operator can edit the virtual-host configuration.
In either case the web server must expose only the reviewed PHP entrypoints and public static data.

## Apache shared hosting

The checked-in [root `.htaccess`](../.htaccess) is part of the security boundary. It requires Apache
2.4 with `mod_rewrite`, `AllowOverride All`, and permission to use `Options`. It provides these
rules:

- only `index.php` and the four `_admin/*.php` front controllers can execute directly;
- source, tests, tools, configuration, database, logs, private cache entries, and dependency
  metadata are denied;
- the only public Composer files are AdminYard's exact `demo/style.css` and `demo/script.js` assets;
- only generated top-level `_cache/<name>.<hex>.css|js[.gz]` bundles are public;
- upload directories disable CGI/PHP handlers and deny active document formats.

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
| `config.php`, SQLite database and sidecars | `0600` | contain credentials, hashes, drafts, and private content |
| private cache metadata and backup archives | `0600` | may contain configuration or a database copy |
| `_cache/` and private backup directories | `0750` | writable private application state |
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
