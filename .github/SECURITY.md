# Security policy

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability. Use GitHub's
[private vulnerability reporting form](https://github.com/bolknote/Register/security/advisories/new)
and include:

- the affected Register revision and deployment type;
- a minimal reproduction or request/response transcript;
- the security impact you observed;
- whether the issue is already being exploited or publicly discussed.

Do not include real passwords, session cookies, API keys, private posts, database copies, or other
people's data. Replace secrets with synthetic values while preserving their shape.

We will acknowledge a complete report, keep discussion private while a fix is prepared, and credit
the reporter in the advisory unless anonymity is requested. A public advisory and patched release
will be published after coordinated disclosure.

## Supported versions

Register is currently under active 2.0 development. Security fixes are made on `main`; older S2
releases and unmaintained Register snapshots do not receive separate security backports.

## Operational incidents

If credentials or a backup may have leaked, do not wait for a code release: take the site out of
service if necessary, rotate database/API credentials and the application secret, revoke active
administrator sessions, preserve relevant logs, and restore only from a verified clean backup.
