<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $comment_html */
/** @var string $email */
/** @var string $error */
/** @var string $draft_id */
/** @var string $form_token */
/** @var string $submit_url */
/** @var string $return_url */
?>
<section class="public-auth-panel pending-comment-panel">
    <div class="public-auth-body">
        <?php if ($error !== ''): ?>
        <p class="public-auth-status is-error" role="alert"><?php echo register_htmlencode($error); ?></p>
        <?php else: ?>
        <p><?php echo $trans('We sent a confirmation link to the address below. The link is valid for 15 minutes.'); ?></p>
        <?php endif; ?>
        <p><?php echo $trans('Your comment is saved. If the address is wrong or the link expires, correct your email and request a new link.'); ?></p>
        <form class="public-auth-form pending-comment-email-form" method="post" action="<?php echo register_htmlencode($submit_url); ?>">
            <div class="public-auth-email-action">
                <label class="public-auth-field">
                    <span><?php echo $trans('Email address'); ?></span>
                    <input type="email" name="email" value="<?php echo register_htmlencode($email); ?>" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required>
                </label>
                <button class="public-auth-primary" type="submit"><?php echo $trans('Send a new confirmation link'); ?></button>
            </div>
            <input type="hidden" name="draft" value="<?php echo register_htmlencode($draft_id); ?>">
            <input type="hidden" name="auth_token" value="<?php echo register_htmlencode($form_token); ?>">
        </form>
        <h2><?php echo $trans('Your saved comment'); ?></h2>
        <div class="comment-text pending-comment-preview"><?php echo $comment_html; ?></div>
        <p><a href="<?php echo register_htmlencode($return_url); ?>"><?php echo $trans('Return to the article'); ?></a></p>
    </div>
</section>
