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

Run the production maintenance command regularly, normally once per minute:

```bash
php cron.php
```

It creates a backup when no archive newer than 24 hours exists. Backup failures are logged and do not
stop the other maintenance jobs. Administrators with user-management permission can inspect the
latest archive, create a new one, and download it from **Search & statistics** in the control panel.

A command-line backup can be forced at any time:

```bash
php tools/backup.php
```

For the isolated `./dev` installation use:

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
path is used as written. The directory must be writable by the PHP and cron users and must not be
served by the web server.

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
2. Stop all writes, including the web process, queue worker, and cron command.
3. For SQLite, replace the configured database file with `database.sqlite`. For MySQL/MariaDB,
   import `database.sql` into an empty configured database with `mysql`. For PostgreSQL, import it
   with `psql`.
4. Copy the contents of `media/` into the configured media directory.
5. Restore deployment configuration separately, clear Register's cache, then restart normal cron and
   queue processing.

Automatic backups are daily full snapshots. They are not continuous or incremental, and they do not
replace an off-site backup policy. Copy retained archives to storage with independent credentials and
test restoration periodically.
