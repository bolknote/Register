# Backups

Register creates private, authenticated encrypted database-and-media backups. A retained or
downloaded `register-backup-*.zip.enc` file is a streaming XChaCha20-Poly1305 envelope around a
standard ZIP archive containing:

- a consistent database snapshot (`database.sqlite` or `database.sql`);
- every regular file from the configured media directory under `media/`;
- `manifest.json` with sizes, hashes, the database driver, and the Register version;
- `RESTORE.txt` with a short recovery checklist.

The browser self-updater additionally creates a smaller
`updates/register-update-backup-*.zip.enc` pre-update snapshot in the same private backup
directory. It contains the database and bounded supplemental recovery material, but deliberately
does not duplicate media: releases do not manage uploaded media, while changed application files
have a separate rollback journal. It is created only after active requests stop, so a failed attempt
cannot leave a snapshot that silently predates later writes.
Update snapshots and full backups each retain up to the configured `retention` count; update
snapshots do not replace the latest full archive shown on the System status page and do not postpone
the next scheduled full backup.

Deployment configuration is deliberately excluded because it contains database and application
credentials. By default it also contains the symmetric backup recovery key. That key is never
stored in the database, generated cache, backup directory, or encrypted archive. Default encryption
therefore protects an archive copied or downloaded without the installation configuration; it does
not protect against an attacker who can read both the archive and `config.php` on the live hosting
account. The optional offline-recipient mode below removes that limitation for confidentiality by
keeping the private recovery key off the hosting account.

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
create a new one explicitly, and download it from **System status** in the control panel.
Both manual operations require a fresh current-password check in addition to the authenticated
session and CSRF token. On a host whose request time limit is too short for an automatic full
snapshot, use that explicit action or the command-line backup below; neither creates a separate
queue executor.

A command-line backup can be forced at any time:

```bash
php tools/backup.php
```

For the isolated `./bin/dev` installation use:

```bash
APP_ENV=local php tools/backup.php
```

The command prints the completed encrypted archive path.

## Configuration

New installations include this section in `config.php`:

```php
'backups' => [
    'enabled'              => true,
    'directory'            => null,
    'retention'            => 7,
    'encryption_key'       => 'a-random-installation-secret-of-at-least-32-bytes',
    'recipient_public_key' => null,
],
```

`enabled` controls only scheduled creation; manual creation remains available. `retention` accepts
1–365 archives. With `directory` set to `null`, Register uses an installation-specific private
directory next to the document root. A relative path is resolved from the Register root; an absolute
path is used as written. The directory must be writable by the PHP user and any operator running the
CLI tools, and must not be served by the web server.

The installer generates `encryption_key` with a cryptographically secure random source. Existing
installations without this setting use the separately stored static `security.antispam_secret` when
it is at least 32 bytes; otherwise backup creation fails closed until a new random key is added to
`config.php`. Do not replace either key while retained encrypted backups still depend on it. Before
an intentional rotation, decrypt the retained archives with the old configuration and create fresh
backups with the new key. Follow the complete [secret-rotation runbook](secret-rotation.md) so the
old recovery configuration is retained only with the archives that still need it.

Authenticated streaming encryption requires the Sodium PHP extension (`ext-sodium`). It is a
required runtime dependency and avoids loading a potentially large media archive into memory.

The local development bootstrap stores three archives under `.local/backups/`.

### Optional offline recipient key

For stronger protection against a complete read-only compromise of the hosting account, generate a
recipient keypair on a trusted offline computer:

```bash
php tools/generate-backup-keypair.php /offline/backup-recovery.php
```

The command creates the recovery file with mode `0600` and prints only the public key. Copy the
printed `recipient_public_key` value into the live `config.php`; never upload
`backup-recovery.php`. When this value is present, every new archive gets an independent random
stream key wrapped with the public key. The hosting account can create backups but cannot decrypt
them. Keep `encryption_key` while any older version-1 archives still need it; it is ignored when
creating recipient-encrypted version-2 archives.

Store at least two protected offline copies of the recovery file and test one before relying on the
mode. Anyone who can alter the live public key can redirect future backups to a different recipient,
and anyone who controls the hosting account can still delete or replace archives. Protect the live
configuration from changes, monitor configuration audit events, and copy archives off-site.

## Database utilities

SQLite uses `VACUUM INTO` to create a transactionally consistent snapshot and needs no optional ZIP
or compression extension. MySQL/MariaDB needs `proc_open` and `mysqldump`; PostgreSQL needs
`proc_open` and `pg_dump`. Register starts those utilities without a shell and passes the database
password through a child-process environment variable, never as a command-line argument.

For MySQL/MariaDB and PostgreSQL the dump covers the whole configured database, not only tables with
the Register prefix. Give each installation its own database if the backup must contain only that
installation.

## Decrypt and restore

Copy the original `config.php` (and `config.secrets.php`, when used) to the Register application
directory. Decrypt without booting or connecting to the database:

```bash
php tools/decrypt-backup.php /private/register-backup-20260815-120000-deadbeef.zip.enc
```

By default this writes the same path without `.enc` and refuses to overwrite an existing file. An
explicit output and configuration path can be supplied for an offline recovery:

```bash
php tools/decrypt-backup.php backup.zip.enc restored.zip /recovery/config.php
```

For a recipient-encrypted archive, pass the offline file created by the keypair tool:

```bash
php tools/decrypt-backup.php backup.zip.enc restored.zip /offline/backup-recovery.php
```

The public key stored on the live host is intentionally insufficient for this operation. Version-1
symmetric archives continue to use their matching historical `config.php`, so both envelope
versions remain recoverable during migration.

Any wrong key, modified byte, truncation, reordered frame, or data appended after the authenticated
final frame makes decryption fail and removes the partial plaintext output. Legacy unencrypted
`register-backup-*.zip` files remain discoverable and downloadable during migration, but every newly
created archive is encrypted.

1. Keep both the encrypted archive and decrypted temporary ZIP private. Verify `manifest.json` and
   its database SHA-256 before restoring.
2. Take the site offline so no web request or shutdown phase can write to the database.
3. For SQLite, replace the configured database file with `database.sqlite`. For MySQL/MariaDB,
   import `database.sql` into an empty configured database with `mysql`. For PostgreSQL, import it
   with `psql`.
4. For a full `register-backup-*` archive, copy the contents of `media/` into the configured media
   directory. A `register-update-backup-*` archive has `media.included` set to `false`; preserve the
   existing media directory instead.
5. Restore deployment configuration separately, clear Register's cache, then resume normal
   request-driven queue processing.

Automatic backups are daily full snapshots. They are not continuous or incremental, and they do not
replace an off-site backup policy. Copy encrypted archives and their recovery configuration through
separate protected channels, keep at least one offline copy of the recovery key, and test decryption
and restoration periodically.
