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

Eleven concurrency scenarios verify that a failed eleventh recovery copy preserves
all earlier drafts, and that pruning resumes only after a successful write. Admin
AI title/tag suggestions validate both whole-body and selected-text sources,
including appended text and edits outside the selected source. Concurrent media
renames reject stale edit/create requests while retaining text for a successful
retry. PHP integration tests check rollback of content, tags and media relations,
file retention, and repeated occurrences of one media id with different URLs.

Fifteen admin selection scenarios exercise the actual toolbar with single,
reversed, multiline and multiple selections, mixed wrapping/unwrapping, empty
carets and undo/redo. Image descriptions track individual occurrences even when
several images have the same URL and alt text. Delayed generation follows edits
before an image, ignores deleted/replaced images and manual descriptions, and
supports independent requests for identical images. Inserting a repeated image
before an existing one generates alt text for the newly inserted occurrence.

Nine image-insertion scenarios cover quoted and encoded descriptions, selected
HTML, block/line boundaries, literal markup, inline code, multiple reversed
selections and empty carets. The inserted attributes, surrounding text, undo/redo,
recovery and submitted HTML remain intact. Eleven smart-paragraph scenarios keep
complete preformatted, raw-text and list blocks unchanged, including whitespace,
quoted attributes, uppercase tags, nesting and an unfinished block, while still
formatting surrounding prose. Repeated formatting is stable.

Twenty-six social-preview text scenarios cover both admin editor modes. They
preserve block/line separators, exclude code and non-editorial content, decode
entities, honor cut markers and omit a repeated title. Local descriptions use
whole sentences or words within the same 160-character limit as the server,
including supplementary Unicode characters. Explicit descriptions still win.

Twenty-two admin input scenarios cover unfinished tags in form values, exit
warnings, button and keyboard saves, edits during a save, CSRF retries, failures,
suggestions, paste and delayed AI replies. Block formatting transforms each
selection independently, including multiline/reversed ranges and mixed carets,
with one undo step. Delayed image-alt successes and failures preserve an open
description field, its focus and selection in both browsers; Enter, Escape and
blur retain their usual meaning. Saving commits the open field before capturing
the body and cancels pending generation.

Twenty-eight HTML editor scenarios cover native reload and pagehide with an
unfinished image description, cancellation, undo, and Firefox's restoration of
unsaved textarea values. Block formatting respects adjacent and nested elements,
same-line blocks, plain multiline text, selections and the caret, and round trips
through preformatted text, including its indentation and trailing spaces. Image
descriptions preserve quoted attribute boundaries and unrelated attributes through
manual edits, delayed AI, undo and redo. Literal image markup in comments, raw-text
elements and attributes stays untouched.

Twenty-eight public caption/input scenarios cover concurrent AI descriptions when
an overlay caption is cancelled or committed unchanged, including undo/redo,
recovery and saving. Clean cancellation restores text and styles without adding
an edit, while retaining earlier body changes. Ctrl/Cmd+S works from overlay and
inline captions and the image-alt field, including failed saves and retries.
Home/End, selection and arrow keys stay in alt and link inputs; keyboard menu
navigation, Enter and Escape keep their usual behavior.
Inline captions isolate Select All and keep a bounded undo/redo history until
commit adds one post-history step. Keyboard and beforeinput undo cannot modify
earlier body edits. Unchanged captions retain concurrent AI descriptions when
finished with Enter, Escape or Tab; undo, redo, recovery and saving preserve them.
Leaving by Tab, clicking the body or beside an image, dropping media, and switching
captions restore body editing and retain each caption as a separate undo step.

Seventeen caption/boundary scenarios cover replacing, deleting and cutting a
paragraph before an image, including the context menu, backwards selections,
undo/redo, recovery and saving. Unselected images keep their captions and wrappers;
explicitly selected media can still be deleted. New and existing overlay captions
have local keyboard and native-menu undo, with cancellation retaining earlier body
edits and commit adding one post-history step. Whole-body and selected AI replies
finish caption sessions before changing the DOM, restore typing and history, and
retain unrelated caption edits; stale whole-body replies leave the active caption
intact. Both caption types use the native clipboard menu without losing selection.

Nine field/history scenarios cover independent keyboard and native-menu undo in
the title, body redo, saving, and fresh editing sessions. AI title suggestions and
restored draft titles participate in the same bounded title history. Body history
retains individual image nodes so pending AI descriptions survive undo/redo without
repeated requests, including identical images and a deletion undone before the
reply. Deleted images and manual descriptions still reject stale responses;
generated descriptions remain undoable and persist through recovery and saving.

Eight media/AI scenarios cover pending image and audio uploads. AI actions whose
source includes a pending upload ask the author to wait; selected text outside it
can still be processed. Uploads started after an AI request reject a stale reply.
After completion, AI works normally and keeps the attachment through undo/redo,
recovery and saving, without serializing temporary progress markup or blob URLs.
Ten tag-paste scenarios cover both editors: insertion at either edge, partial and
full selections, separators, normalization, duplicates, failed saves and retry.
The public editor falls back to native paste when the combined text is invalid
or exceeds the tag limit, preserving the input for correction.

Eighteen upload/clipboard scenarios use actual Copy, Cut and Paste, including the
context menu. Moving or duplicating pending images/audio retains the upload and
each occurrence through completion, undo/redo, recovery and saving. Pasting after
completion still works, including automatic image descriptions; failed uploads
and closed sessions cannot insert stale progress markup or replace selected text.

Eighteen admin save scenarios include the production fetch interceptor. Network
failures, HTML error responses, JSON errors, malformed successes and a failed CSRF
retry show one useful error and retain the unsaved draft. Both textarea and
CodeMirror editors unlock after a failed creation and can save successfully on
retry. Four social-preview scenarios cover body edits, undo/redo, AI replies,
explicit description/image overrides and initial draft recovery in both modes.

Four alt-layout scenarios use the production admin styles and HTML editor wrappers
on desktop, narrow screens, new posts and fullscreen. The image panel remains
outside CodeMirror without covering its source or escaping the editor. Keyboard
saving commits the description, failed saves retain it, and body typing resumes.
Eight template-field scenarios cover input/change events, periodic checks, stale
responses and saving in both editor modes, without creating an unnecessary body
draft. Two document-lifecycle scenarios repeatedly replace the preview template,
including recovery after an error, and retain scrolling in both directions without
old listeners or animations interfering.

Twenty-seven media-insertion scenarios retain existing images and their pending
descriptions when splitting paragraphs, including nested formatting, repeated
images, manual overrides and undo/redo. Block anchors survive splitting without
duplicates. Typing follows an inserted image at the
start, middle and end of headings, quotes, code and lists, during and after upload.
Rejected files preserve content, selection, history, empty paragraphs and active
captions; mixed file lists still upload supported attachments. All cases check
the local recovery copy and submitted HTML.

Twelve native image-drag scenarios move the original media block, including
captions, links, figures, overlays and inline images. Pending uploads and image
descriptions survive the move and undo/redo without starting new requests.
Dropping onto the source or outside the body leaves the post unchanged; failed
uploads leave no temporary references. Recovery and saving retain the result.

Thirteen admin AI scenarios track the selected occurrence through surrounding
edits and clipboard insertions. Deleting or replacing the source rejects its
reply, even if identical text takes its place or the edit is undone. Ambiguous
DOM diffs across repeated text also reject the reply. Success, failure and save
release the tracked range; undo/redo and saving preserve unrelated edits.

Twelve mixed-media scenarios cover image/audio order, repeated attachments and
insertion in the middle or at the end of text. Continued typing follows the last
attachment during and after uploads. The batch is one insertion for undo/redo;
recovery and saving retain all files and subsequent text in order.
