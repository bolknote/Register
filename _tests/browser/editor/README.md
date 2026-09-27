# Editor browser regressions

These tests execute the real `post-inplace.js` with real DOM, Selection, editing
commands and input/keyboard events. Unlike the VM unit tests, they exercise undo
history. The fixture exposes private functions **only in its loopback test server**;
production code has no testing API. Upload and AI responses are deterministic
stubs. No blog, database, credentials or external services are used.

```sh
npm ci --prefix _tests/browser/editor
cd _tests/browser/editor
npx playwright install chromium firefox
npm test
```

The Quality workflow runs both Chromium and Firefox and blocks releases on failure.
For interactive inspection in Opera or another local browser:

```sh
node _tests/browser/editor/server.mjs
```

Open `http://127.0.0.1:8082/` for post editing or
`http://127.0.0.1:8082/comment.html` for comment editing, then click
**Run regressions**. Each case reports its assertions in the page.
`EDITOR_TEST_PORT` selects another loopback port;
`EDITOR_TEST_REVISION=<git ref>` runs the current tests against an older asset.
The five original failures reproduce at `d8ebfbb3` (10 failing scenarios).

The 22 post scenarios and four comment scenarios run in each browser. Post
coverage includes inline code and partial removal, mixed native/DOM formatting,
unlink, overlay and inline captions,
full/partial/nested list conversion, and asynchronous media insertion (undo before
or after completion, interleaved text, failures, and editor cancellation). They
also check multiline clipboard data, typing groups, selection restoration, redo
branching, and the history size bound. Clipboard and upload payloads are test
fixtures; actual browser editing, event handlers and history are not mocked.

The body has one history for native input and DOM-based tools. Transactions retain
DOM structure and both selection endpoints. Upload completion amends the insertion
in every retained snapshot, so undo/redo never starts another upload or resurrects
a pending placeholder. The history is local to the editing session, bounded to
100 states and 4 Mi characters of serialized snapshots (keeping at least the
current and preceding state for unusually large documents), and discarded on close.
Comment coverage includes normalizing a legacy empty browser paragraph, one-line
Enter behavior, immediate optimistic rendering after save, and rollback after a
failed request.

The runner also opens `/recovery.html` for nine integration scenarios against the
real public editor and browser storage. These cover actual page reloads, offline
typing, new untitled posts, explicit recovery versus a newer server revision,
save/discard cleanup, account isolation, local HTML sanitisation, oversized copies,
completed media on page exit, independent tabs, and URL-only edits with redirects.
Only server responses are stubbed; storage, navigation and editor events are real.

Save regressions also exercise delayed and failed requests through the public
editor and the real admin form module (`/admin.html`), with CodeMirror and a plain
textarea. They check that public
editing is locked until a save settles, failed image/audio uploads stop a waiting
save, and re-uploading allows a complete retry. Admin tests verify that text typed
during an update stays unsaved and recoverable, including after a CSRF retry,
and that creation is locked until its redirect or unlocked after failure.

Fourteen additional review scenarios check switching editors when a fresh recovery
copy cannot be written (oversized text or unavailable storage), while retaining
explicit discard and successful restoration. Admin tabs with a textarea or
CodeMirror cannot erase or overwrite a newer draft when idle, closing, or finishing
an earlier save. Delayed AI title/tag suggestions preserve subsequent manual input
in both editors, and unchanged targets still accept suggestions. Image/audio
renaming updates the complete undo/redo history, recovery copies, and a retried save
after a conflict without adding a history step.

Nine recovery/preview scenarios cover cancelling either tab after a draft is
restored elsewhere, including after a third tab deletes the local copy. Files
exposed through recovery remain available until saved or expired by the server;
private uploads still release on cancellation. Restored image overlays retain
their text, formatting and identity when edited again. The admin preview follows
title-only changes from typing, the periodic check and AI without changing or
creating a draft of the body, with both textarea and CodeMirror editors.
Saving an existing or new post after undoing uploads also retains shared files
and still cleans private uploads. The PHP integration suite verifies the cleanup
contract through the actual controller, registry and stored files.

Eight asynchronous preview scenarios delay the initial template request until
newer title/body changes are rendered. A stale success, HTTP error, network error,
or malformed JSON response cannot replace the latest preview, in either admin
editor mode.

Nine lifecycle scenarios cover native reload after quota, oversized-copy or
storage-access failures; cancelling the exit keeps the latest text, and recovery
after storage becomes available needs no warning. Older image/audio copies fetch
current URLs after another tab renames and publishes their uploads. PHP integration
checks that this reconciliation preserves the files and their usage without
renaming published attachments. Saving an admin article cancels pending AI title,
tag, proofreading and image-alt requests; failed saves permit another attempt,
and successful creation redirects without leaving a dirty, locked form.
