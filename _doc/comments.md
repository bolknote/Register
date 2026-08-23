# Comments

Register renders threaded comments on posts and pages. Comment submission remains a normal HTML
`POST`; JavaScript progressively enhances the same server-rendered form and is not required for
publishing or replying.

## Editor and stored format

The public form contains a compact rich-text editor for bold, italic, strikethrough, links, quotes,
code, and ordered or unordered lists. It deliberately has no image, audio, video, or AI controls.
Paste and drag-and-drop are reduced to the same small HTML dialect before submission.

Formulas are entered as source such as `$$f(x) = x^2-\sqrt{x}$$`. A complete pair is rendered
locally in the editor. Clicking the rendered formula, moving into it, or deleting beside it restores
the original source for editing. `Ctrl+Enter` submits on Windows and Linux; `Command+Enter` submits
on macOS.

The browser mirrors the editor contents into the ordinary `text` form field. The server then parses
and sanitizes the fragment again in [`CommentHtml`](../_include/src/Comment/CommentHtml.php), without
requiring an extra PHP extension. Canonical rich comments carry a private storage marker; legacy
plain-text and BBCode rows remain renderable. Scripts, event attributes, forms, styles, iframes,
arbitrary media, unsafe links, and unknown attributes never enter canonical comment HTML.

## Identity and email sign-in

An authenticated participant comments under the name and email of the account; the public form does
not ask for a second name or address. A guest supplies a name and email. When email-link sign-in is
enabled, the guest may prepare the comment while requesting a one-time link. The same validation,
sanitizer, replay protection, rate limiting, and spam checks run before the message is queued, and
the pending comment is published only after the link is opened successfully.

Email-link sign-in is disabled on new sites. Enable it under **Settings → Public sign-in** only after
verifying that PHP `mail()` from the production host reaches the intended mailbox. VK ID and Yandex
buttons likewise remain hidden until their application credentials are configured.

## Submission and moderation

`CommentController` rejects a submission when comments are disabled, the target or reply parent is
invalid, the form token has expired, text or identity fields are invalid, the size limit is exceeded,
or the honeypot/rate-limit policy rejects it. The server derives the identity from the authenticated
session when one exists.

The configurable detector supports local scoring, Akismet, or shadow comparison. It returns `ham`,
`spam`, `blatant`, `failed`, or `disabled`; local assessments retain hashed evidence and never need
raw visitor identifiers in the audit tables. Blatant spam is rejected. A failed detector, a spam
verdict, or an otherwise forced review is not published directly. Valid links in otherwise clean
comments are accepted as HTML but force moderation.

Manual premoderation is controlled by `REGISTER_PREMODERATION` under **Settings → Comments**. When it
is enabled, ordinary `ham` comments also wait for review. A published comment is stored with its
optional parent, subscriber and moderator notifications are scheduled, and the browser is redirected
to the comment anchor.

## Thread rendering and unread comments

Comments are fetched in chronological order and assembled into a tree on the server. Missing
parents, forward references, and cycles in imported data are shown safely as top-level comments.
The public theme communicates nesting through spacing and indentation rather than connector lines.
Visual indentation stops after three levels; deeper replies keep their logical parent and show the
addressee explicitly.

Reply links carry the parent in the query string as a no-JavaScript fallback. Progressive enhancement
moves the same form below the selected comment and updates its hidden parent field. For authenticated
participants, the header counter links to the first relevant unread comment. Site participants see
new comments in their posts; other users always see direct replies and see every new comment in a
discussion only after selecting **Subscribe to new comments** there. The same subscription continues
to control email delivery when the account has an email address.
