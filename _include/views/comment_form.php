<?php

declare(strict_types = 1);

/** @var string $id */
/** @var string $action */
/** @var string $cancelReplyUrl */
/** @var string[] $syntaxHelpItems */
/** @var callable $trans */
/** @var string $antispamToken */
/** @var string|null $name */
/** @var string|null $email */
/** @var bool|null $show_email */
/** @var bool|null $subscribed */
/** @var string|null $text */
/** @var int|null $parent_id */
/** @var int|null $reply_number */
/** @var string|null $reply_name */
/** @var \Register\Core\Model\AuthenticatedPublicUser|null $authenticatedUser */

$name         ??= '';
$email        ??= '';
$show_email   ??= false;
$subscribed   ??= false;
$text         ??= '';
$parent_id    = isset($parent_id) && $parent_id > 0 ? $parent_id : null;
$reply_number = isset($reply_number) && $reply_number > 0 ? $reply_number : 0;
$reply_name   ??= '';
$authenticatedUser ??= null;

$commentReturnPath = parse_url(html_entity_decode($action), PHP_URL_PATH);
if (!\is_string($commentReturnPath) || $commentReturnPath === '') {
    $commentReturnPath = '/';
}
$authLoginUrl = $makeLink('/auth') . '?return=' . rawurlencode($commentReturnPath . '#add-comment');
$authLogoutUrl = $makeLink('/auth/logout');

if ($authenticatedUser instanceof \Register\Core\Model\AuthenticatedPublicUser) {
    $name  = $authenticatedUser->commentName();
    $email = $authenticatedUser->email;
}

?>
<section class="comment-form-block" id="add-comment" aria-labelledby="comment-form-title">
    <h2 class="comment form" id="comment-form-title"><?php echo $trans('Post a comment'); ?></h2>
    <div class="comment-reply-context"<?php if ($parent_id === null): ?> hidden<?php endif; ?>>
        <span><?php echo $trans('Replying to'); ?>
            <a class="comment-reply-target" href="<?php echo $reply_number > 0 ? '#' . $reply_number : '#comments-title'; ?>"><?php echo $reply_name !== '' ? register_htmlencode($reply_name) : '№&nbsp;' . $reply_number; ?></a>
        </span>
        <button class="comment-reply-cancel" type="button"><?php echo $trans('Cancel reply'); ?></button>
    </div>
    <div class="comment-public-auth">
    <?php if ($authenticatedUser instanceof \Register\Core\Model\AuthenticatedPublicUser): ?>
        <span class="comment-public-auth-copy"><?php echo $trans('Commenting as'); ?> <strong><?php echo register_htmlencode($authenticatedUser->displayName()); ?></strong></span>
        <form class="comment-public-auth-logout public-auth-logout-form" method="post" action="<?php echo register_htmlencode($authLogoutUrl); ?>" data-public-auth-form="logout">
            <input type="hidden" name="csrf_token" value="<?php echo register_htmlencode($authenticatedUser->publicLogoutCsrfToken()); ?>">
            <input type="hidden" name="return_path" value="<?php echo register_htmlencode($commentReturnPath . '#add-comment'); ?>">
            <button type="submit"><?php echo $trans('Sign out'); ?></button>
        </form>
    <?php else: ?>
        <span class="comment-public-auth-copy"><?php echo $trans('Comment as guest or sign in to keep track of replies.'); ?></span>
        <a class="public-auth-open comment-public-auth-login" href="<?php echo register_htmlencode($authLoginUrl); ?>" data-public-auth-open><?php echo $trans('Sign in'); ?></a>
    <?php endif; ?>
    </div>
    <form method="post" name="post_comment" id="comment-form" action="<?php echo register_htmlencode($action); ?>">
        <div class="input text comment-text-input">
            <label id="comment-text-label" for="comment-text"><?php echo $trans('Your comment'); ?></label>
            <?php
            $editorId    = 'comment-text';
            $editorValue = $text;
            $editorRows  = 9;
            require __DIR__ . '/comment_editor.php';
            ?>
        </div>
        <?php if ($authenticatedUser instanceof \Register\Core\Model\AuthenticatedPublicUser): ?>
        <div class="comment-authenticated-identity" hidden>
            <input type="hidden" name="name" value="<?php echo register_htmlencode($name); ?>">
            <input type="hidden" name="email" value="<?php echo register_htmlencode($email); ?>">
        </div>
        <?php else: ?>
        <div class="comment-identity" data-comment-guest-identity>
            <p class="input name">
                <label for="comment-name"><?php echo $trans('Your name'); ?></label>
                <input id="comment-name" type="text" name="name" value="<?php echo register_htmlencode($name); ?>" maxlength="50" size="40" autocomplete="name" />
            </p>
            <p class="input email">
                <label for="comment-email"><?php echo $trans('Your email'); ?></label>
                <input id="comment-email" type="email" name="email" value="<?php echo register_htmlencode($email); ?>" maxlength="80" size="40" autocomplete="email" />
                <small><?php echo $trans('Email privacy note'); ?></small>
            </p>
        </div>
        <?php endif; ?>
        <?php if (!$authenticatedUser instanceof \Register\Core\Model\AuthenticatedPublicUser || $email !== ''): ?>
        <div class="comment-options">
            <label for="show_email" title="<?php echo $trans('Show email label title'); ?>"><input type="checkbox" id="show_email" name="show_email" <?php if ($show_email) echo 'checked="checked" '; ?>/><?php echo $trans('Show email label'); ?></label>
            <label for="subscribed" title="<?php echo $trans('Subscribe label title'); ?>"><input type="checkbox" id="subscribed" name="subscribed" <?php if ($subscribed) echo 'checked="checked" '; ?>/><?php echo $trans('Subscribe label'); ?></label>
        </div>
        <?php endif; ?>
        <details class="comment-formatting">
            <summary><?php echo $trans('Formatting help'); ?></summary>
            <div class="comment-syntax"><?php foreach ($syntaxHelpItems as $item) { echo $item . "\n"; } ?></div>
        </details>
        <p class="visually-hidden" aria-hidden="true">
            <label>Homepage
                <input type="text" name="homepage" value="" tabindex="-1" autocomplete="off" /></label>
        </p>
        <input type="hidden" name="id" value="<?php echo register_htmlencode($id); ?>" />
        <input type="hidden" name="antispam_token" value="<?php echo register_htmlencode($antispamToken); ?>" />
        <input type="hidden" name="parent_id" value="<?php echo $parent_id ?? ''; ?>" />
        <input type="hidden" name="reply_number" value="<?php echo $reply_number; ?>" />
        <input type="hidden" name="reply_name" value="<?php echo register_htmlencode($reply_name); ?>" />
        <p class="input buttons">
            <input class="comment-submit" type="submit" name="submit" value="<?php echo $trans('Submit'); ?>" />
            <?php if (!$authenticatedUser instanceof \Register\Core\Model\AuthenticatedPublicUser): ?>
            <button class="comment-email-submit" type="submit" name="email_login" value="1"><?php echo $trans('Sign in by email and publish'); ?></button>
            <?php endif; ?>
        </p>
    </form>
    <a class="comment-form-origin" href="<?php echo register_htmlencode($cancelReplyUrl); ?>" hidden></a>
</section>
