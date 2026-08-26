# Secret rotation on shared hosting

Register does not require environment variables or an external secret manager. Long-lived
deployment secrets live in private PHP configuration files with mode `0600`; password and session
verifiers live in the database. This runbook assumes that the operator may have only a hosting
control panel, a file manager, and phpMyAdmin. Shell commands shown below can be run on a trusted
local machine when the hosting account has no terminal.

## Inventory

| Credential or secret | Normal location | Rotation effect |
|---|---|---|
| Database password | `config.php`, section `database` | The application cannot start until the hosting database and file agree. |
| Administrator password | Password hash in the `users` table | Register revokes every session belonging to that user. |
| Passkeys | Public credentials in the WebAuthn tables; private keys stay on devices | Revoking a credential prevents that device from signing in. |
| Recovery codes | SHA-256 verifiers in the recovery-code table | Regeneration invalidates every previous unused code. |
| AI and Akismet API keys | Private dynamic-secret file | Provider access changes without exposing the value in the database, cache, or HTML. |
| Antispam HMAC secret | `security.antispam_secret` in `config.php`, or `S2_ANTISPAM_SECRET` in the private dynamic-secret file on legacy installations | Existing comment-form tokens and antispam visitor cookies become invalid; pseudonymous audit and rate-limit identifiers start a new generation. |
| Anonymous visitor HMAC secret | `REGISTER_VISITOR_SECRET` in the private dynamic-secret file | Existing visitor cookies and browser-storage tokens stop resolving to their previous identity. |
| Backup encryption key | `backups.encryption_key` in `config.php` | New archives use the new key; old archives still require the old key. |
| Administrator session and CSRF tokens | Hashed session rows in `users_online`; raw token only in the browser cookie | Deleting the session row invalidates both the session and tokens derived from it. |

The private dynamic-secret file is the path configured by `security.secret_file`. Register first
tries an installation-specific file beside the document root when the hosting account permits it.
On ordinary shared hosting it uses `config.secrets.php` inside the single application/document root
only after an HTTP boundary probe has proved that the supplied server policy prevents source
download.
The managed keys are `S2_AKISMET_KEY`, `REGISTER_AI_API_KEY`, `S2_ANTISPAM_SECRET`, and
`REGISTER_VISITOR_SECRET`. The database and generated configuration cache contain only
`$register-private-secret:v1$` for non-empty managed values.

## Before every planned rotation

1. Record the start time and the person performing the change, without copying secret values into
   a ticket or chat.
2. Create a fresh encrypted Register backup and verify that it can be decrypted. Preserve the
   current `config.php` and private dynamic-secret file separately through a protected channel.
3. Confirm that another administrator or recovery method works before changing login credentials.
4. Put the site into the hosting provider's maintenance mode for database credentials, internal
   HMAC secrets, or the backup key. API-key replacement normally needs no downtime.
5. Prepare rollback copies with mode `0600`. Never use a public web directory, e-mail attachment,
   browser paste service, or world-readable file as temporary storage.

Generate 32 random bytes as 64 hexadecimal characters on a trusted machine when a new internal or
backup secret is needed:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

The command itself contains no secret, but its output does. Copy the output directly into the
destination and clear the terminal scrollback or clipboard when the procedure is complete.

## AI and Akismet keys

For a planned replacement, create a new key at the provider first, save it in **Configuration** in
Register, verify one real request, and only then revoke the old provider key. Saving or clearing a
key requires the current administrator password. Register writes the value to the private
dynamic-secret file, replaces the database and cache value with the marker, and never renders the
stored key back into HTML.

For a suspected leak, revoke the exposed key at the provider immediately. A short service outage is
safer than leaving a working stolen credential. Then create and save the replacement, inspect the
provider audit history, and review `security-audit.jsonl` for unexpected configuration changes.

After either procedure, verify that:

- the relevant database `config.value` is `$register-private-secret:v1$`;
- the private file is outside the document root where possible and has mode `0600`;
- `_cache/cache_config.php` contains the marker, not the key;
- direct HTTP requests cannot download the private file or cache.

## Administrator credentials, passkeys, recovery codes, and sessions

Change a user's password or permissions through **Users**. Register asks for the acting
administrator's current password and revokes all sessions of the changed user. Use a unique
password phrase of at least 12 characters; do not transmit it together with the database backup.

Open the affected user's edit page to revoke lost or untrusted passkeys and regenerate recovery
codes. Store the new recovery codes offline; regeneration makes every old code unusable. A passkey's
private key cannot be rotated server-side: register a replacement authenticator and revoke the old
public credential.

Use **Sessions** to delete other active sessions. During a site-wide credential incident, delete all
rows from the prefixed `users_online` table using the database control panel; this immediately logs
out every administrator. Do not delete the `users` table. Sign in again only from a trusted device,
then change all privileged passwords and inspect every registered passkey.

## Database credentials

Prefer creating a new database user with the same minimum privileges, because it gives a safe
rollback window:

1. Enable maintenance mode and create the replacement database user in the hosting panel.
2. Download `config.php`, edit the `database.user` and `database.password` values locally, and upload
   it through a private path. Use an atomic rename when the file manager supports it and restore
   mode `0600`.
3. Restart PHP or reset OPcache from the hosting panel when that option exists, then load one public
   page and one administration page.
4. Run a harmless read and a reversible write through Register, create a new encrypted backup, and
   only then remove the old database user.

If the provider permits only one database user, change its password and replace `config.php` within
the same maintenance window. Keep maintenance active until both reads and writes work. Do not put a
database password on a command line where it can appear in process listings or shell history.

## Internal HMAC secrets

Rotate these secrets only after a suspected disclosure or when an explicit key-lifecycle policy
requires it. Their values do not grant database or administrator access, but they protect signed
browser state and privacy-reduced identifiers.

For `S2_ANTISPAM_SECRET`, first check `config.php`. A valid `security.antispam_secret` takes
precedence over the legacy dynamic value; replace that configured value when it exists. Otherwise
replace the `S2_ANTISPAM_SECRET` entry in the private dynamic-secret file. Replace
`REGISTER_VISITOR_SECRET` in that same private file. Preserve every other array entry exactly,
upload the complete file atomically when possible, and restore mode `0600`.

Delete the private `cache_config.php`, restart PHP/OPcache when the hosting panel allows it, and then
load the public site once. Register must start without a dynamic-secret error. Do not change the
database marker to an empty value: that would make Register remove the corresponding private entry
on the next regeneration.

Expected consequences are important:

- rotating the antispam secret invalidates comment forms already open in browsers and the 30-day
  antispam visitor cookie; users must reload before commenting;
- hashes written before and after the rotation in security/CSP logs, spam reputation, and rate-limit
  data cannot be correlated;
- rotating the visitor secret invalidates one-year visitor identity tokens;
  returning readers receive a new anonymous identity, analytics continuity breaks, and reaction
  uniqueness starts from that new identity.

After rotation, verify that both managed database rows still contain the marker, submit a test
comment, resolve a visitor identity, and check that no secret value appears in the database,
generated cache, HTML, application log, security audit log, or CSP log.

## Backup encryption key

Never overwrite the only key for retained archives. Before a planned rotation, copy the old
`config.php` off-host, decrypt every archive that must remain recoverable, and verify its manifest.
Then replace `backups.encryption_key` with a new random value, restore mode `0600`, create a fresh
backup, and test offline decryption with the new configuration.

Keep an old-key recovery configuration only as long as its matching archives are retained, in a
different protected location from those archives. If the old key and archives were both exposed,
assume those backups are readable: rotation cannot retroactively protect them. Preserve evidence,
create a clean new generation, and securely expire the compromised copies according to the incident
policy.

If `backups.recipient_public_key` is enabled, the corresponding private key must remain only in the
offline recovery configuration. Generate a new pair with `tools/generate-backup-keypair.php`, store
and test the new recovery file first, and only then replace the live public key. Keep the old offline
file for as long as any archive encrypted to that recipient remains. Exposure of the live public key
does not reveal existing archives, but unauthorized replacement can redirect all future archives;
treat an unexplained public-key change as a configuration-integrity incident.

## Emergency order of operations

When the scope is unclear, contain access in this order:

1. enable maintenance mode and preserve logs and file timestamps;
2. revoke exposed provider/API keys and database credentials;
3. delete administrator sessions, replace privileged passwords, revoke suspect passkeys, and
   regenerate recovery codes;
4. rotate internal HMAC secrets if their configuration files or database values were readable;
5. rotate the backup key when both its configuration and any encrypted archive may have escaped;
6. restore service from a verified revision and backup, then monitor authentication, configuration,
   CSP, upload, and provider audit logs.

Do not destroy the only forensic copy while containing the incident. Record identifiers, hashes,
timestamps, and affected accounts, but never copy live secret values into the incident report.
