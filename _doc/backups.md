# Backups

Register creates private database-and-media backups. A backup is a standard ZIP archive containing:

- a consistent database snapshot (`database.sqlite` or `database.sql`);
- every regular file from the configured media directory under `media/`;
- `manifest.json` with sizes, hashes, the database driver, and the Register version;
- `RESTORE.txt` with a short recovery checklist.

Deployment configuration is deliberately excluded because it contains database and application
credentials. Treat every archive as secret: the database includes password hashes, unpublished
content, email addresses, and other editorial data.

## Automatic and manual creation

No cron entry is required. Normal HTTP traffic places one durable automatic-backup job in the
request-driven queue and creates an archive when no backup newer than 24 hours exists. The job is
protected by both the shared queue lease and a non-blocking filesystem lock; a killed PHP process
leaves the job available for a later request, and the next attempt removes narrowly matched
abandoned work files. Backup failures follow the normal visible retry/dead-letter policy and do not
stop unrelated runnable jobs.

A full database-and-media snapshot cannot be safely interrupted in the middle of a database utility
or filesystem call. Register therefore starts it only when at least four seconds remain in the
shutdown slice. Administrators with user-management permission can inspect the latest archive,
create a new one explicitly, and download it from **Search & statistics** in the control panel. On a
host whose request time limit is too short for an automatic full snapshot, use that explicit action
or the command-line backup below; neither creates a separate queue executor.

A command-line backup can be forced at any time:

```bash
php tools/backup.php
```

For the isolated `./bin/dev` installation use:

```bash
APP_ENV=local php tools/backup.php
```

The command prints the completed archive path.

## Configuration

New installations include this section in `config.php`:

```php
'backups' => [
    'enabled'   => true,
    'directory' => null,
    'retention' => 7,
],
```

`enabled` controls only scheduled creation; manual creation remains available. `retention` accepts
1–365 archives. With `directory` set to `null`, Register uses an installation-specific private
directory next to the document root. A relative path is resolved from the Register root; an absolute
path is used as written. The directory must be writable by the PHP user and any operator running the
CLI tools, and must not be served by the web server.

The local development bootstrap stores three archives under `.local/backups/`.

## Database utilities

SQLite uses `VACUUM INTO` to create a transactionally consistent snapshot and needs no optional ZIP
or compression extension. MySQL/MariaDB needs `proc_open` and `mysqldump`; PostgreSQL needs
`proc_open` and `pg_dump`. Register starts those utilities without a shell and passes the database
password through a child-process environment variable, never as a command-line argument.

For MySQL/MariaDB and PostgreSQL the dump covers the whole configured database, not only tables with
the Register prefix. Give each installation its own database if the backup must contain only that
installation.

## Restore

1. Keep the archive private and verify `manifest.json` before restoring.
2. Take the site offline so no web request or shutdown phase can write to the database.
3. For SQLite, replace the configured database file with `database.sqlite`. For MySQL/MariaDB,
   import `database.sql` into an empty configured database with `mysql`. For PostgreSQL, import it
   with `psql`.
4. Copy the contents of `media/` into the configured media directory.
5. Restore deployment configuration separately, clear Register's cache, then resume normal
   request-driven queue processing.

Automatic backups are daily full snapshots. They are not continuous or incremental, and they do not
replace an off-site backup policy. Copy retained archives to storage with independent credentials and
test restoration periodically.
