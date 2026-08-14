# Development

Install development dependencies with Composer:

```bash
composer install
```

Start a fully isolated SQLite development copy (dependencies, initial database, and web server) with:

```bash
./dev
```

It uses `APP_ENV=local`, stores mutable data in `.local/`, listens on `127.0.0.1:8080` by default,
and starts a queue worker beside the web server. The worker applies search-index and thumbnail jobs
automatically; both processes stop together on Ctrl+C. Use `S2_DEV_PORT=9000 ./dev` to select
another port.

Production installations do not need cron. Successful HTTP requests detach their response where the
SAPI permits it and advance a bounded slice of durable background work from a shutdown callback.
That work publishes scheduled content, drains asynchronous jobs, performs anti-spam maintenance,
and creates the daily private backup when it is due. With no traffic, work waits until the next
request. Use `php tools/run-background.php` for an explicit operational drain, especially on SAPIs
that cannot detach a response and therefore leave heavyweight jobs queued. The control-panel search
rebuild remains repair tooling. See [Backups](backups.md) for storage and restore details.

Run unit and integration tests with Codeception:

```bash
php _vendor/bin/codecept run unit
php _vendor/bin/codecept run integration
```

Run the complete quality gate:

```bash
composer check
```

The gate runs PHP parallel lint, PHPCS with Slevomat, ShellCheck, actionlint, PHPStan at its
maximum level, Psalm at level 1, strict Phan, PHPMD, PHP 8.3–8.5 compatibility checks,
dependency analysis, Rector in dry-run mode, and the unit and integration suites. ShellCheck
and actionlint must be available on `PATH`; the quality CI workflow installs pinned versions.

## Code navigation

The repository includes a CodeGraph MCP configuration in `.codex/config.toml` and indexing
rules in `.codegraphignore`. Start a new Codex task after cloning the repository so the MCP
server is loaded; it then keeps the first-party graph current while excluding dependencies,
generated caches, local data, and bundled third-party frontend libraries. Its graph database is
kept in the ignored `.local/codegraph-runtime/` directory so CodeGraph instances for other
repositories cannot contend for or contaminate the Register index. Do not configure a
workspace-specific CodeGraph server in the user-level `~/.codex/config.toml`: each repository
must own exactly one `[mcp_servers.codegraph]` entry in its trusted project configuration.

Acceptance tests require the built-in PHP server and test databases.
Use the repository helper script to prepare caches, start the server on `localhost:8881`,
run the acceptance suite, and stop the server:

```bash
./test_sh
```
