# Outgoing mail

Register has one provider-neutral outgoing-mail layer for sign-in links, moderator messages,
subscriber notifications, and control-panel delivery tests. It is designed to work inside a
single-root shared-hosting package: Composer, a resident worker, root access, and a binary such as
`sendmail` are not required on the production account.

## Transport choice

Configure mail under **Settings → Outgoing mail**:

- **Auto** uses authenticated SMTP when an SMTP host is present and otherwise uses PHP `mail()`.
- **SMTP** connects directly to the configured relay. STARTTLS on port 587 is the recommended
  portable setting; implicit TLS normally uses port 465. The timeout is bounded to keep a failed
  relay from occupying a shared PHP worker indefinitely.
- **PHP mail()** hands the complete MIME message to the host's local mail system. It is the zero-cost
  fallback when the provider already operates a suitable relay. The optional `-f` envelope argument
  improves bounce handling and SPF alignment, but can be disabled for hosts that reject the fifth
  `mail()` argument.
- **Disabled** makes the mail subsystem explicitly unavailable. Email-link sign-in is then hidden,
  even if its separate feature switch is enabled.

SMTP passwords and optional DKIM private keys use Register's dynamic secret store. They are not
stored in the database, generated configuration cache, page cache, backup archive, browser HTML, or
delivery metrics. Preserve `config.secrets.php` (or the configured external secret file) separately
when moving or restoring an installation.

## Sender identities and deliverability

The visible `From`, SMTP login, envelope sender, and `Reply-To` are deliberately independent:

- `From` is the identity shown to the recipient. Use an address in a domain controlled by the site.
- The envelope sender receives bounces and is evaluated by SPF. Leaving it blank reuses `From`.
- The SMTP login is only a relay credential and need not be displayed in the message.
- `Reply-To` is optional. Moderator notifications override it with the comment author's address so
  the moderator can answer the author without forging `From`.

Transport acceptance alone does not prove inbox delivery. A production domain should also have:

1. an SPF record authorizing the relay that actually sends the message;
2. a DKIM signature made either by that relay or by Register;
3. a DMARC record, with the authenticated SPF or DKIM domain aligned with `From`;
4. a stable relay hostname and reverse DNS, which are normally the provider's responsibility.

Prefer a provider-managed DKIM signature when it is available. Register's DKIM fields are for a
relay or local `mail()` path that does not sign: create a private key off-host, publish the public key
at `<selector>._domainkey.<domain>`, and paste the PEM private key or its base64 representation into
the secret field. Never publish or commit the private key. Do not enable application signing merely
to add a second signature to mail that the SMTP provider already signs correctly.

## Delivery model

Email sign-in links and the explicit control-panel test are sent synchronously because their caller
needs an immediate accepted/failed result. Comment notifications are durable queue jobs, one
idempotent job per comment and recipient. A slow mail relay therefore does not delay comment
publication or moderation. Temporary failures use the normal exponential retry policy; invalid
configuration, invalid messages, and permanent SMTP 5xx responses go directly to the failed-job
state instead of wasting every retry.

Subscription messages include both a normal unsubscribe link and the RFC 8058 one-click headers.
Mail clients can therefore unsubscribe with the standard authenticated POST request; malformed
one-click requests are rejected without consuming the signed link.

The request shutdown runner processes bounded queue slices on an ordinary shared host. If the
provider offers cron, a one-minute job can reduce delivery latency on a quiet site without changing
the consistency model:

```bash
/opt/php/bin/php /home/account/public_html/tools/run-queue.php --seconds=20 --jobs=20
```

Use the real PHP path and document root supplied by the host. The command takes the same global
lease as web-triggered work, so overlapping invocations do not create parallel delivery runs.

## Verification and monitoring

Open **System status → Outgoing mail** after saving settings. The block shows:

- configuration readiness and the resolved transport;
- ready, delayed, and permanently failed comment-mail jobs;
- accepted and failed transport calls for the last hour and day, with p50/p95 execution time;
- the last privacy-minimized event;
- best-effort presence checks for SPF, DMARC, and the configured DKIM selector;
- a CSRF-protected test form.

The delivery log is a bounded mode-`0600` JSONL file in the private log directory. It records the
message type, transport, duration, status, safe error code/message, and a keyed recipient hash. It
never records recipient addresses, subjects, message bodies, authentication links, or credentials.
The log confirms that PHP or SMTP accepted a message; only seeing the test in the destination Inbox
or Spam folder confirms end-to-end delivery. Inspect that received message's original headers to
verify SPF, DKIM, and DMARC results.

## Shared-hosting rollout checklist

1. Upgrade the code and let the normal schema check add the optional mail settings. Existing sites
   inherit their former webmaster name and address so an upgrade does not silently change identity.
2. Replace the inherited address with a mailbox in the site's own domain when necessary.
3. Start with the host's documented SMTP service. If it costs extra or is unavailable, select PHP
   `mail()` and use a local-domain `From` and envelope sender.
4. Save, return to System status, and resolve every configuration error.
5. Send a test to a mailbox outside the hosting provider. Check Inbox, Spam, and the complete
   authentication results rather than treating the green “accepted” state as delivery proof.
6. Only after that test succeeds, enable email-link sign-in. Comment notifications may be enabled
   independently and remain observable in the queue.
7. Re-test after moving hosts, changing DNS, rotating a password/key, or changing the sender domain.

If PHP `mail()` returns success but messages disappear, the application has no later delivery status
to inspect. Use the host's mail log/control panel or switch to SMTP, whose connection and response
errors are observable. If the fifth argument is forbidden, disable the envelope option and repeat
the test; then confirm that the provider still supplies an aligned Return-Path.
