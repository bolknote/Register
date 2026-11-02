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
/** @var string $initial_mode */
/** @var array<string, string> $method_urls */
$auth_is_dialog = true;
$auth_title_id = 'public-auth-title';
?>
<dialog class="public-auth-dialog" id="public-auth-dialog" aria-labelledby="public-auth-title">
    <div class="public-auth-dialog-card">
        <?php require __DIR__ . '/public_auth_methods.php'; ?>
    </div>
</dialog>
