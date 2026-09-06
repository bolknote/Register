# Editor browser regressions

These tests execute the real `post-inplace.js` with real DOM, Selection, editing
commands and input/keyboard events. Unlike the VM unit tests, they exercise undo
history. The fixture exposes private functions **only in its loopback test server**;
production code has no testing API. Upload responses are deterministic stubs,
and AI is disabled. No blog, database, credentials or external services are used.

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

Open `http://127.0.0.1:8082/` and click **Run regressions**. Each case reports its
assertions in the page. `EDITOR_TEST_PORT` selects another loopback port;
`EDITOR_TEST_REVISION=<git ref>` runs the current tests against an older asset.
The five original failures reproduce at `d8ebfbb3` (10 failing scenarios).

The 21 scenarios run in each browser. Coverage includes inline code and partial
removal, mixed native/DOM formatting, unlink, overlay and inline captions,
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
