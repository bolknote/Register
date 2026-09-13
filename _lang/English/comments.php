<?php

declare(strict_types = 1);

return [

	'Save comment'             => 'Copy your comment somewhere to prevent its loss.',
	'Go back'                  => 'Go <a href="#" data-history-back>back</a> and&nbsp;fix errors.',
	'Fix error'                => 'Fix errors before sending the comment.',
	'Email subject'            => 'Comment to %s',
	'Comment sent'             => 'Comment has been sent',
	'Comment sent info'        => '<p>Your comment has been successfully sent. It will be published after the verification.</p><p>Meanwhile, you can <a href="%1$s" id="back_to_commented">return to the content</a> or&nbsp;visit <a href="%2$s">the main page</a>.</p>',

	'Unsubscribed OK'          => 'You have been successfully unsubscribed',
	'Unsubscribed OK info'     => 'You have been successfully unsubscribed from mailing comments.',

	'Unsubscribed failed'      => 'You have not been unsubscribed',
	'Unsubscribed failed info' => 'Probably, you followed an incorrect or outdated link.',

	'Comment preview'          => 'Comment preview',
	'Comment preview info'     => 'Your comment has not been saved yet! Do not forget to press the “Submit” button after editing.',
    'Comment check passed'     => 'The comment was published automatically. Hide it if it is not appropriate.',
    'Comment check failed'     => 'The comment is hidden and awaiting review. Publish it if it is appropriate.',

    'Email pattern'            =>
		'Hello, <name>.

You have received this e-mail because you subscribed to comments on the content
“<title>”,
located at the address:
<url>

The author of the new comment is <author>.

----------------------------------------------------------------------
<text>
----------------------------------------------------------------------

This e-mail has been sent automatically. If you reply, the author
of the site will receive your answer. To unsubscribe, follow the link

<unsubscribe>',
	'Email HTML pattern'       =>
		'<p>Hello, <strong><name></strong>.</p>

<p>You subscribed to comments on <a href="<url>">“<title>”</a>.</p>
<p>Comment author: <strong><author></strong>.</p>

<hr>
<div><text></div>
<hr>

<p>If you reply to this email, the site author will receive your answer.</p>
<p><a href="<unsubscribe>">Unsubscribe from comments on this content</a>.</p>
<p><small>This is an automated notification.</small></p>',
	'Email reply pattern'      =>
		'Hello, <name>.

<author> replied to your comment on
“<title>”. You can find the reply here:
<url>

----------------------------------------------------------------------
<text>
----------------------------------------------------------------------

This e-mail has been sent automatically.',
	'Email reply HTML pattern' =>
		'<p>Hello, <strong><name></strong>.</p>

<p><strong><author></strong> replied to your comment on <a href="<url>">“<title>”</a>.</p>

<hr>
<div><text></div>
<hr>

<p><a href="<url>">Open the reply on the site</a>.</p>
<p><small>This is an automated notification.</small></p>',
	'Email moderator pattern'  =>
		'Hello, <name>.

You have received this e-mail, because you are the moderator.
A new comment on
“<title>”,
has been received. You can find it here:
<url>

<author> is the comment author.

----------------------------------------------------------------------
<text>
----------------------------------------------------------------------

<status>

This e-mail has been sent automatically. If you reply, the author
of the comment will receive your answer.',
	'Email moderator HTML pattern' =>
		'<p>Hello, <strong><name></strong>.</p>

<p>A new comment was posted on <a href="<url>">“<title>”</a>.</p>
<p>Comment author: <strong><author></strong>.</p>

<hr>
<div><text></div>
<hr>

<p><strong><status></strong></p>
<p><a href="<url>">Open the comment on the site</a>.</p>
<p>If you reply to this email, the comment author will receive your answer.</p>
<p><small>This is an automated notification.</small></p>',

	// Comment errors
	'Error message'            => 'The following errors must be corrected before your comment can be saved:',
	'missing_text'             => 'You have forgotten to enter the comment text.',
	'missing_nick'             => 'You have forgotten to enter your name.',
	'long_text'                => 'The message cannot be larger than%s bytes.',
    'links_in_text'            => 'Remove http:// or https:// from links. The author will add links to the content if they are valuable.',
    'spam_message_rejected'    => 'Your comment cannot be saved because it contains spam. Please contact the author of the site if you believe this is a mistake.',
	'long_nick'                => 'Is your name length more than 50 symbols? It is something strange...',
	'question'                 => 'You gave the wrong answer to the question. Try again.',
	'form_expired'             => 'The comment form is invalid or has expired. Reload the page and try again.',
		'email'                    => 'Invalid e-mail. Please enter a valid address. It is used for sign-in and notifications and is never published.',
	'disabled'                 => 'Sorry, but&nbsp;you cannot send comments&nbsp;to this site at&nbsp;this moment. Try it later.',
	'no_item'                  => 'The destination page cannot be detected due to an error. Go to the page you have commented and try again (you can copy and paste the comment text).',
	'invalid_parent'           => 'The comment you replied to is no longer available. Choose another comment or post a new top-level comment.',
	'Confirm your email before the comment is published.' => 'After you confirm your email, your first comment will be reviewed before publication.',
	'Send confirmation link' => 'Send confirmation link',
	'Email sign-in is unavailable' => 'Email sign-in is currently unavailable.',
	'Unable to send sign-in link' => 'Unable to send a sign-in link. Please try again later.',

];
