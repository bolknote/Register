<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var bool $email_enabled */
/** @var bool $vk_enabled */
/** @var bool $yandex_enabled */
/** @var string $password_url */
/** @var string $email_url */
/** @var string $vk_url */
/** @var string $mail_url */
/** @var string $ok_url */
/** @var string $yandex_url */
/** @var string $return_path */
/** @var string $form_token */
?>
<dialog class="public-auth-dialog" id="public-auth-dialog" aria-labelledby="public-auth-title">
    <div class="public-auth-dialog-card">
        <header class="public-auth-dialog-header">
            <h2 id="public-auth-title"><?php echo $trans('Sign in'); ?></h2>
            <button class="public-auth-close" type="button" data-public-auth-close aria-label="<?php echo register_htmlencode($trans('Close')); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </header>
        <?php require __DIR__ . '/public_auth_methods.php'; ?>
    </div>
</dialog>
