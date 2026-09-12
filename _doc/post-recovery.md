# Public post editor recovery

The public inline editor keeps a local recovery copy while writing a new post or
editing an existing one. A saved copy is offered above the relevant post, or near
the new-post control, after reloading or returning to the page. The user chooses
**Restore text** or **Delete local copy**. The editor never replaces server content
automatically and restoring does not publish or save a post.

When the server revision differs from the copy's starting revision, the offer
explicitly warns that the site has changed. Restoration keeps the current server
revision and the ordinary server-side optimistic-concurrency check still applies
to the subsequent save. Cancelling restores the current server content.

The copy includes title, formatted body, tags including unfinished input, date,
post address when available, and completed upload IDs. Authorisation tokens are
never stored. Keys include the installation's tag endpoint and the authenticated
editor's user ID; another account or a guest does not see these offers. Separate
editing sessions have independent copy IDs, so tabs cannot overwrite one another.
Deleting a stale offer cannot remove its newer snapshot.

Copies expire seven days after their last update. Each is limited to 512 Ki UTF-16
code units of serialized JSON (approximately 1 MiB of browser storage); an account
can retain up to ten copies and 2 Mi code units in total. Oldest copies are pruned
when the bounded store is full. The local browser profile is not an encrypted
vault: a person with access to that profile can inspect browser storage. Normal
server authorisation remains required to display editing controls and save posts.

Changes are captured after a 250 ms debounce, with a synchronous flush when the
document is hidden or leaves the page. Storage exceptions and oversized text show
a visible error without preventing editing or replacing the previous valid copy.
Successful save or an explicit confirmed discard removes that session's copy.
Switching editors retains the copy. This supports writing while a previously
opened editor has no connection; it is not an offline server-synchronisation queue.

Completed uploads referenced by a recovery copy are not released on page exit.
They still obey the server's existing pending-upload expiry and may need to be
uploaded again after an extended interruption. Incomplete uploads and temporary
blob previews are not recoverable. The recovery message asks the author to check
media. Local HTML is treated as untrusted: scripts, custom active markup and embeds
are removed during restoration, unsafe URLs and event attributes are rejected,
and the UI warns when markup was changed. The server version stays available by
cancelling before saving.

Verification: `node --test _tests/javascript/post-recovery.test.mjs` covers storage
isolation, expiry, limits, malformed data, storage failures and stale deletion.
`npm test --prefix _tests/browser/editor` runs actual editor/reload scenarios in
Chromium and Firefox, including offline typing, untitled new posts, conflicting
server revisions, explicit discard, account changes, HTML sanitisation, storage
limits, completed media and simultaneous tabs.
