# Editor browser regressions

These tests execute the real `post-inplace.js` with real DOM, Selection, editing
commands and input/keyboard events. Unlike the VM unit tests, they exercise undo
history. The fixture exposes private functions **only in its loopback test server**;
production code has no testing API. Upload and AI responses are deterministic
stubs. These fixture regressions use no configured blog, persistent database, credentials or external
services. A separate end-to-end suite below starts a disposable PHP installation.

```sh
npm ci --prefix _tests/browser/editor
cd _tests/browser/editor
npx playwright install chromium firefox webkit
npm test
```

The Quality workflow runs Chromium, Firefox and WebKit and blocks releases on failure.
To diagnose one engine locally, run `EDITOR_TEST_BROWSER=webkit npm test` (or
`chromium` / `firefox`); the default runs all three.
The reaction-control regressions also run independently with
`node reactions-tests.mjs`. They use the real PHP renderer and reaction repository
against a disposable in-memory SQLite database, the production reaction JavaScript
and both public stylesheets. The suite checks server and visual order, zero likes,
hydration, imported/selected emoji, old cached markup, hover timing, keyboard
navigation, optimistic selection/removal and failed-request rollback. Only visitor
identity and HTTP responses are stubbed. Chromium exercises a native held touch
through CDP; Firefox/WebKit exercise the touch pointer timer followed by a real
touchscreen tap, since Playwright exposes no held-touch API for those engines.
`REACTIONS_TEST_REVISION=<git ref> node reactions-tests.mjs` uses an older reaction
asset to reproduce the pre-fix primary-control regression.

Tag display regressions also run independently with `node tag-display-tests.mjs`.
The actual PHP post/search views and Russian typography render multiword tags with
hyphenated words. Without client JavaScript, the tests measure real interword space
widths, continuous visible labels, pill height/centering, and mobile wrapping in
light and dark themes in all three engines. No configured blog or database is used.

Editor-menu regressions also run independently with `node context-menu-tests.mjs`.
The extra menu button is available only with touch input, independently of viewport
width. Mouse contexts use a real right click and Shift+F10 instead. All three engines
check selection preservation, responsive menu bounds, scrolling only in short
viewports, and visibility before editing and after resizing. The PHP end-to-end
suite also checks keyboard and touch access using the actual server-rendered controls.

Saving a post containing a script or style element performs a full document load:
fragment insertion cannot initialize scripts or grant the document's CSP nonce.
The HTML-block and PHP end-to-end suites check immediately usable published
controls, authored styles, repeated saves, and exact stored source. Plain prose and
static HTML insertions keep the existing in-place update without a document load.

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

The 34 post scenarios and five comment scenarios run in each browser. Post
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

Social-preview text scenarios cover both admin editor modes. They
preserve block/line separators, exclude code and non-editorial content, decode
entities, honor cut markers and omit a repeated title. Local descriptions use
whole sentences or words within the same 160-character limit as the server,
including supplementary Unicode characters. Explicit descriptions still win.
Whitespace is normalized before removing repeated titles and choosing a lead;
titles and invisible whitespace alone before a cut fall back to the article body.

ActivityPub preview scenarios cover both admin editor modes. Editing body, title,
tags, federation or publication settings invalidates pending and rendered previews;
resetting or saving the form does too. Rebuilding uses the current fields, and an
obsolete response, including delayed JSON decoding, cannot replace the new result
or unlock its request button. A preview started during a save is also invalidated
when that save completes. Previewing preserves unsaved changes and does not save.
Server validation errors identify the rejected fields, remain separate readable
lines, and allow rebuilding a preview after correcting the form.

Twenty-two admin input scenarios cover unfinished tags in form values, exit
warnings, button and keyboard saves, edits during a save, CSRF retries, failures,
suggestions, paste and delayed AI replies. Block formatting transforms each
selection independently, including multiline/reversed ranges and mixed carets,
with one undo step. Delayed image-alt successes and failures preserve an open
description field, its focus and selection in all three browsers; Enter, Escape and
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
retry. Ten additional scenarios use the production notification component: all
global and field errors appear together, field labels identify the rejected
values, malformed error entries are ignored, and each failed retry replaces the
previous messages, including session expiration. Successful retries clear the
notification and saved draft.
Four social-preview scenarios cover body edits, undo/redo, AI replies,
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

Two shortcut scenarios keep alt and CodeMirror search fields independent from
source formatting, while retaining keyboard saves and formatting in the source.
Six alt-response scenarios cover success, HTTP/network errors and malformed JSON
after moving to another image, a paragraph or a second pending request. The panel
follows the current cursor; each image retains its result and retry action.
Three scroll scenarios cover multiline opening/closing tags, attributes, comments
and void elements, checking actual paragraphs in both scroll directions.

Twelve native formatting scenarios exercise Ctrl+B/K/O with both CodeMirror
platform maps and caret or reversed selection. Formatting runs once, before any
platform editing command, and native undo/redo, recovery and saving retain all
source. Six smart-paragraph scenarios preserve multiline tags, quoted attribute
values and comments without inserting breaks into markup. Eighteen paragraph
conversion scenarios retain anchors and compatible attributes with a caret,
complete blocks and disjoint selections; explicit alignment still replaces or
clears the old value. Each case checks undo/redo, recovery and submitted HTML.

Ten comment scenarios format prose around inline and multiline comments while
retaining comment contents, existing tags and idempotence. Seven Ctrl+D scenarios
duplicate each cursor's line once, preserving indentation, empty lines and Unicode
columns. Thirteen paragraph scenarios format every cursor's block, including
shared, nested and empty blocks, while retaining attributes and separator cursors.
Native mouse gestures create multiple cursors; formatting, undo and redo retain
their positions and the primary cursor. All cases check recovery and saving.

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

Fifteen media-library scenarios load the production file browser, jQuery tree
and fetch interceptor. Overlapping file selections and drops retain the upload
indicator until all requests settle, including reversed replies and manual
refreshes. Earlier replies preserve newer selections. Network, HTTP and malformed
responses show one useful error and allow selecting the same file again; successful
retries refresh the actual file list. Filenames sort naturally by their numbers,
including values beyond JavaScript's safe integer range.

Twelve folder scenarios reject outdated file lists and failures when switching
folders, refreshing the same folder or returning to a previous folder. Cancelled
requests retain the latest loading state without showing errors. Renaming and
moving a folder refreshes the selected branch, including nested folders selected
while the operation was pending, and preserves unrelated selections. File links,
image insertion and subsequent uploads use the current folder path and token.
Twelve more scenarios distinguish a failed current request from an empty directory,
reject malformed responses without leaving the browser busy, and load the same
folder successfully on retry. Authentication and permission failures handle HTML,
null and malformed error payloads while preserving valid server messages.

Two deletion scenarios use filenames and folder names containing `%s`, dollar
patterns and Unicode. The confirmation displays these names literally without
freezing, cancellation preserves the item, and confirmation deletes the exact
requested name.

Fourteen file-operation scenarios restore insertion details when filtering a
multiple selection down to one file, reconcile renames after an intervening
refresh or folder round trip, and retain selections in unrelated folders.
Cross-tree file moves clear old insertion links and counts, preserve folder
navigation and nested folders, and reload the current source or destination.
Moving the entire file list, network failures, rejected renames and moves, and
partially completed moves also leave the library usable with current file URLs.

Four filtered-selection scenarios exercise Shift ranges in both directions with
text and type filters. Counts, deletion confirmations, delete requests and file
moves exclude hidden files. Two keyboard scenarios check all arrow directions,
Control/Shift modifiers and Space across hidden rows and at list boundaries.
Three focus scenarios clear hidden or detached keyboard targets after filtering
and refreshing; empty views ignore Rename and Delete, and clearing a filter
restores keyboard navigation and renaming.

Sixteen deletion and folder-creation scenarios exercise the real confirmation
dialog, cancellation, deletion of all files, refreshes and navigation during
pending deletes, and recovery after server or network failures. Folder creation
updates the selected folder's actual name, path and upload token, including a
folder selected before the server replies. A failed creation removes only its
provisional node, preserving later selections and other successfully created
folders. Subsequent image insertions and uploads verify that the selected
destination remains usable.

Fifteen folder-recovery scenarios cover rejected, disconnected and null responses
for renaming, moving and deleting folders. Recovery restores only the affected
name or tree position, retaining the selected folder, its insertion link and
upload token, nested folders, context buttons, and independently created folders.
Each operation can be retried successfully after a network failure without
repeating the original server mutation during local recovery.

`npm run test:durability` runs the focused recovery and timeout regressions in all
three browsers. Admin recovery stores all editable form fields, the base revision
and an independent copy per tab under the installation/account namespace. Copies
are restored explicitly, retained without an age limit, and bounded to ten records and 2 Mi characters
per namespace; each record is limited to 512 Ki characters. Tokens are excluded.
Older body-only admin copies have no installation or account identity, so they
remain in storage without being restored automatically into an authenticated form.

`npm run test:reliability` covers cross-tab removal and pruning of active admin
drafts, stalled AI/alt/ActivityPub requests and JSON responses, and HTML-block
preview growth/shrink, plain-text clipboard boundaries and removing/replacing
an expired recovery attachment. It runs in all three
browsers and is included in `npm test`.

Pending server uploads retain their seven-day cleanup limit. Restoring a local
draft whose upload expired reports the unavailable attachment and requires its
removal or replacement before saving. Schema generation 37 retains allocated
media ids separately so deleted uploads cannot be confused with newer files.
Legacy recovery copies cannot adopt uploads allocated after the upgrade. A new
managed image/audio element retains its identity marker through history and
recovery; existing post-media relations remain valid without that marker.

`npm run test:e2e` uses PHP (8.3+) with the Composer dependencies to exercise an
actual disposable Register installation. It creates a private temporary SQLite
DB, cache, session directory and secret file, starts the normal development router
on a free loopback port, and removes all its files in `finally`. It never opens
`config.php`, `config.local.php` or the developer database. The temporary
`config.e2e-<random>.php` in the repository contains fixture paths and is removed
on exit. The workflow runs this suite after the browser regressions.

The end-to-end suite uses real login forms, session cookies, editor controls,
PHP validation/controllers and SQLite writes. It loses a creation response after
the DB commit and verifies that retry returns the same post without another row.
It also edits and reloads a post, restores title/body/tags in the admin editor and
verifies their stored values. Service workers are blocked in this fault-injection
context so Playwright can intercept the lost response reliably. On failure the
server log is saved to `_tests/_output/editor-e2e-server.log`.

The public editor entry coordinates components under `_assets/register/editor/`:
field decorations, text/media boundaries, undo history, tags and recovery. These
components accept explicit dependencies. Both public and admin editors use the
same bounded storage and request deadline helpers. Creation retries retain the
same operation identifier across local recovery; a replay with later draft edits
opens the created post for review without discarding those edits. Save deadlines
include JSON decoding, and uploads/AI/media renaming have bounded requests too.
