<?php

declare(strict_types = 1);

/** Shared variables are declared by public_auth_dialog.php or public_auth_panel.php. */
$hasProviders = $vk_enabled || $yandex_enabled;
$hasMethodsPanel = $hasProviders || $email_enabled;
?>
<div
    class="public-auth-body"
    data-public-auth-error="<?php echo register_htmlencode($trans('Unable to sign in. Please try again.')); ?>"
    data-public-auth-default-mode="<?php echo $hasMethodsPanel ? 'methods' : 'password'; ?>"
>
    <div class="public-auth-status" role="status" aria-live="polite" hidden></div>

    <?php if ($hasMethodsPanel): ?>
    <section class="public-auth-mode-panel" data-public-auth-mode-panel="methods">
        <?php if ($hasProviders): ?>
        <div class="public-auth-providers" aria-label="<?php echo register_htmlencode($trans('Sign in with')); ?>">
            <?php if ($vk_enabled): ?>
            <a class="public-auth-provider public-auth-provider-vk" href="<?php echo register_htmlencode($vk_url); ?>" data-register-native-navigation>
                <span class="public-auth-provider-logo" aria-hidden="true">VK</span><span><?php echo $trans('VK ID'); ?></span>
            </a>
            <?php endif; ?>
            <?php if ($yandex_enabled): ?>
            <a class="public-auth-provider public-auth-provider-yandex" href="<?php echo register_htmlencode($yandex_url); ?>" data-register-native-navigation>
                <span class="public-auth-provider-logo" aria-hidden="true">Я</span><span><?php echo $trans('Yandex'); ?></span>
            </a>
            <?php endif; ?>
            <?php if ($vk_enabled): ?>
            <a class="public-auth-provider public-auth-provider-mail" href="<?php echo register_htmlencode($mail_url); ?>" data-register-native-navigation>
                <span class="public-auth-provider-logo" aria-hidden="true">@</span><span>Mail.ru</span>
            </a>
            <a class="public-auth-provider public-auth-provider-ok" href="<?php echo register_htmlencode($ok_url); ?>" data-register-native-navigation>
                <span class="public-auth-provider-logo" aria-hidden="true">OK</span><span><?php echo $trans('Odnoklassniki'); ?></span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($email_enabled): ?>
        <?php if ($hasProviders): ?>
        <div class="public-auth-divider"><span><?php echo $trans('or'); ?></span></div>
        <?php endif; ?>
        <form
            class="public-auth-form public-auth-email-form"
            method="post"
            action="<?php echo register_htmlencode($email_url); ?>"
            data-public-auth-form="email"
            data-busy-label="<?php echo register_htmlencode($trans('Sending')); ?>"
        >
            <div class="public-auth-email-action">
                <label class="public-auth-field">
                    <span><?php echo $trans('Your email'); ?></span>
                    <input type="email" name="email" autocomplete="email" inputmode="email" required placeholder="name@example.ru">
                </label>
                <button class="public-auth-primary" type="submit"><?php echo $trans('Send sign-in link'); ?></button>
            </div>
            <p class="public-auth-email-hint"><?php echo $trans('No password: we will send a one-time link.'); ?></p>
            <details class="public-auth-name-section">
                <summary><?php echo $trans('Add your name'); ?></summary>
                <label class="public-auth-field">
                    <span><?php echo $trans('Your name'); ?></span>
                    <input type="text" name="name" autocomplete="name" maxlength="80">
                </label>
            </details>
            <input type="hidden" name="auth_token" value="<?php echo register_htmlencode($form_token); ?>">
            <input type="hidden" name="return_path" value="<?php echo register_htmlencode($return_path); ?>">
        </form>
        <?php endif; ?>

        <button class="public-auth-mode-switch" type="button" data-public-auth-mode-open="password">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="3"></circle><path d="m10.5 13 8-8M15 8l3 3M13 10l3 3"></path></svg>
            <span><?php echo $trans('Site login and password'); ?></span>
            <svg class="public-auth-mode-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m8 5 5 5-5 5"></path></svg>
        </button>
    </section>
    <?php endif; ?>

    <section class="public-auth-mode-panel public-auth-password-section" data-public-auth-mode-panel="password"<?php if ($hasMethodsPanel): ?> hidden<?php endif; ?>>
        <?php if ($hasMethodsPanel): ?>
        <button class="public-auth-mode-back" type="button" data-public-auth-mode-open="methods">
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12 5-5 5 5 5"></path></svg>
            <span><?php echo $trans('Other sign-in methods'); ?></span>
        </button>
        <?php endif; ?>
        <div class="public-auth-password-heading">
            <h3><?php echo $trans('Site login and password'); ?></h3>
            <p><?php echo $trans('For site members and administrators.'); ?></p>
        </div>
        <form
            class="public-auth-form public-auth-password-form"
            method="post"
            action="<?php echo register_htmlencode($password_url); ?>"
            data-public-auth-form="password"
            data-busy-label="<?php echo register_htmlencode($trans('Signing in')); ?>"
        >
            <label class="public-auth-field">
                <span><?php echo $trans('Login'); ?></span>
                <input type="text" name="login" autocomplete="username" required>
            </label>
            <label class="public-auth-field">
                <span><?php echo $trans('Password'); ?></span>
                <input type="password" name="pass" autocomplete="current-password" required>
            </label>
            <label class="public-auth-remember"><input type="checkbox" name="remember_me" value="1" checked><span><?php echo $trans('Remember me'); ?></span></label>
            <input type="hidden" name="auth_token" value="<?php echo register_htmlencode($form_token); ?>">
            <input type="hidden" name="return_path" value="<?php echo register_htmlencode($return_path); ?>">
            <button class="public-auth-primary" type="submit"><?php echo $trans('Sign in'); ?></button>
        </form>
    </section>
</div>
