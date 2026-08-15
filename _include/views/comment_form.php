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

$name         ??= '';
$email        ??= '';
$show_email   ??= false;
$subscribed   ??= false;
$text         ??= '';
$parent_id    = isset($parent_id) && $parent_id > 0 ? $parent_id : null;
$reply_number = isset($reply_number) && $reply_number > 0 ? $reply_number : 0;
$reply_name   ??= '';

?>
<section class="comment-form-block" id="add-comment" aria-labelledby="comment-form-title">
    <h2 class="comment form" id="comment-form-title"><?php echo $trans('Post a comment'); ?></h2>
    <div class="comment-reply-context"<?php if ($parent_id === null): ?> hidden<?php endif; ?>>
        <span><?php echo $trans('Replying to'); ?>
            <a class="comment-reply-target" href="<?php echo $reply_number > 0 ? '#' . $reply_number : '#comments-title'; ?>"><?php echo $reply_name !== '' ? s2_htmlencode($reply_name) : '№&nbsp;' . $reply_number; ?></a>
        </span>
        <button class="comment-reply-cancel" type="button"><?php echo $trans('Cancel reply'); ?></button>
    </div>
    <form method="post" name="post_comment" id="comment-form" action="<?php echo s2_htmlencode($action); ?>">
        <p class="input text">
            <label for="comment-text"><?php echo $trans('Your comment'); ?></label>
            <textarea id="comment-text" cols="50" rows="9" name="text"><?php echo s2_htmlencode($text); ?></textarea>
        </p>
        <div class="comment-identity">
            <p class="input name">
                <label for="comment-name"><?php echo $trans('Your name'); ?></label>
                <input id="comment-name" type="text" name="name" value="<?php echo s2_htmlencode($name); ?>" maxlength="50" size="40" autocomplete="name" />
            </p>
            <p class="input email">
                <label for="comment-email"><?php echo $trans('Your email'); ?></label>
                <input id="comment-email" type="email" name="email" value="<?php echo s2_htmlencode($email); ?>" maxlength="80" size="40" autocomplete="email" />
                <small><?php echo $trans('Email privacy note'); ?></small>
            </p>
        </div>
        <div class="comment-options">
            <label for="show_email" title="<?php echo $trans('Show email label title'); ?>"><input type="checkbox" id="show_email" name="show_email" <?php if ($show_email) echo 'checked="checked" '; ?>/><?php echo $trans('Show email label'); ?></label>
            <label for="subscribed" title="<?php echo $trans('Subscribe label title'); ?>"><input type="checkbox" id="subscribed" name="subscribed" <?php if ($subscribed) echo 'checked="checked" '; ?>/><?php echo $trans('Subscribe label'); ?></label>
        </div>
        <details class="comment-formatting">
            <summary><?php echo $trans('Formatting help'); ?></summary>
            <div class="comment-syntax"><?php foreach ($syntaxHelpItems as $item) { echo $item . "\n"; } ?></div>
        </details>
        <p class="visually-hidden" aria-hidden="true">
            <label>Homepage
                <input type="text" name="homepage" value="" tabindex="-1" autocomplete="off" /></label>
        </p>
        <input type="hidden" name="id" value="<?php echo s2_htmlencode($id); ?>" />
        <input type="hidden" name="antispam_token" value="<?php echo s2_htmlencode($antispamToken); ?>" />
        <input type="hidden" name="parent_id" value="<?php echo $parent_id ?? ''; ?>" />
        <input type="hidden" name="reply_number" value="<?php echo $reply_number; ?>" />
        <input type="hidden" name="reply_name" value="<?php echo s2_htmlencode($reply_name); ?>" />
        <p class="input buttons">
            <input class="comment-submit" type="submit" name="submit" value="<?php echo $trans('Submit'); ?>" />
            <input class="comment-preview" type="submit" name="preview" value="<?php echo $trans('Preview'); ?>" />
        </p>
    </form>
    <a class="comment-form-origin" href="<?php echo s2_htmlencode($cancelReplyUrl); ?>" hidden></a>
</section>
