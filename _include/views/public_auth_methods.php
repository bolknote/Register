<?php

declare(strict_types = 1);

/** Shared variables are declared by public_auth_dialog.php or public_auth_panel.php. */
$hasMethodsPanel = $email_enabled || $vk_enabled || $yandex_enabled;
$mode = $hasMethodsPanel ? 'methods' : 'password';
if ($hasMethodsPanel && ($initial_mode === 'password' || ($initial_mode === 'email' && $email_enabled))) {
    $mode = $initial_mode;
}

$headings = [
    'methods' => $trans('Sign in'),
    'email' => $trans('Email sign-in'),
    'password' => $trans('Password sign-in'),
];
$providers = [];
if ($yandex_enabled) {
    $providers[] = ['class' => 'yandex', 'label' => $trans('Yandex'), 'logo' => 'Я', 'url' => $yandex_url];
}
if ($vk_enabled) {
    $providers[] = ['class' => 'vk', 'label' => $trans('VK ID'), 'logo' => 'VK', 'url' => $vk_url];
    $providers[] = ['class' => 'mail', 'label' => 'Mail.ru', 'logo' => '@', 'url' => $mail_url];
    $providers[] = ['class' => 'ok', 'label' => $trans('Odnoklassniki'), 'logo' => 'OK', 'url' => $ok_url];
}
?>
<header class="<?php echo $auth_is_dialog ? 'public-auth-dialog-header' : 'public-auth-panel-header'; ?>">
    <?php if ($hasMethodsPanel): ?>
    <a class="public-auth-mode-back" href="<?php echo register_htmlencode($method_urls['methods']); ?>" data-public-auth-mode-open="methods" data-register-native-navigation aria-label="<?php echo register_htmlencode($trans('Back to sign-in methods')); ?>"<?php if ($mode === 'methods'): ?> hidden<?php endif; ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6"></path></svg>
    </a>
    <?php endif; ?>
    <h2 id="<?php echo $auth_title_id; ?>" data-public-auth-title><?php echo $headings[$mode]; ?></h2>
    <?php if ($auth_is_dialog): ?>
    <button class="public-auth-close" type="button" data-public-auth-close aria-label="<?php echo register_htmlencode($trans('Close')); ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
    </button>
    <?php endif; ?>
</header>
<div class="public-auth-body" data-public-auth-error="<?php echo register_htmlencode($trans('Unable to sign in. Please try again.')); ?>" data-public-auth-default-mode="<?php echo $mode; ?>">
    <div class="public-auth-status" role="status" aria-live="polite" hidden></div>
    <?php if ($hasMethodsPanel): ?>
    <section class="public-auth-mode-panel" data-public-auth-mode-panel="methods" data-public-auth-heading="<?php echo register_htmlencode($headings['methods']); ?>"<?php if ($mode !== 'methods'): ?> hidden<?php endif; ?>>
        <p class="public-auth-methods-hint"><?php echo $trans('Choose a sign-in method'); ?></p>
        <div class="public-auth-methods">
            <?php if ($email_enabled): ?>
            <a class="public-auth-method" href="<?php echo register_htmlencode($method_urls['email']); ?>" data-public-auth-mode-open="email" data-register-native-navigation>
                <span class="public-auth-method-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 6 9 7 9-7"></path></svg></span>
                <span><?php echo $trans('Email link'); ?></span>
                <svg class="public-auth-method-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"></path></svg>
            </a>
            <?php endif; ?>
            <a class="public-auth-method" href="<?php echo register_htmlencode($method_urls['password']); ?>" data-public-auth-mode-open="password" data-register-native-navigation>
                <span class="public-auth-method-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="3"></circle><path d="m10.5 13 8-8M15 8l3 3M13 10l3 3"></path></svg></span>
                <span><?php echo $trans('Site login and password'); ?></span>
                <svg class="public-auth-method-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"></path></svg>
            </a>
            <?php foreach ($providers as $provider): ?>
            <form class="public-auth-provider-form" method="post" action="<?php echo register_htmlencode($provider['url']); ?>">
                <input type="hidden" name="auth_token" value="<?php echo register_htmlencode($form_token); ?>">
                <button class="public-auth-method public-auth-provider public-auth-provider-<?php echo $provider['class']; ?>" type="submit">
                    <span class="public-auth-provider-logo" aria-hidden="true"><?php echo $provider['logo']; ?></span>
                    <span><?php echo $provider['label']; ?></span>
                    <svg class="public-auth-method-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"></path></svg>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    <?php if ($email_enabled): ?>
    <section class="public-auth-mode-panel" data-public-auth-mode-panel="comment-email" data-public-auth-heading="<?php echo register_htmlencode($trans('Confirm comment by email')); ?>" hidden>
        <form class="public-auth-form" method="post" data-comment-email-form data-register-native-navigation>
            <p><?php echo $trans('We will send a link to confirm and send your comment.'); ?></p>
            <label class="public-auth-field"><span><?php echo $trans('Your name'); ?></span><input name="comment_name" type="text" autocomplete="name" maxlength="50" required></label>
            <label class="public-auth-field"><span><?php echo $trans('Email address'); ?></span><input name="comment_email" type="email" autocomplete="email" inputmode="email" required></label>
            <button class="public-auth-primary" type="submit"><?php echo $trans('Get confirmation link'); ?></button>
        </form>
    </section>
    <section class="public-auth-mode-panel" data-public-auth-mode-panel="email" data-public-auth-heading="<?php echo register_htmlencode($headings['email']); ?>"<?php if ($mode !== 'email'): ?> hidden<?php endif; ?>>
        <form class="public-auth-form public-auth-email-form" method="post" action="<?php echo register_htmlencode($email_url); ?>" data-public-auth-form="email" data-busy-label="<?php echo register_htmlencode($trans('Sending')); ?>">
            <p class="public-auth-email-hint"><?php echo $trans('No password: we will send a one-time link.'); ?></p>
            <label class="public-auth-field">
                <span><?php echo $trans('Email address'); ?></span>
                <input type="email" name="email" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required placeholder="name@example.ru" aria-invalid="false" aria-describedby="<?php echo $auth_title_id; ?>-email-error">
            </label>
            <span class="public-auth-field-error" id="<?php echo $auth_title_id; ?>-email-error" data-public-auth-email-error role="alert" hidden><?php echo $trans('Enter a valid email address'); ?></span>
            <button class="public-auth-primary" type="submit"><?php echo $trans('Send sign-in link'); ?></button>
            <input type="hidden" name="auth_token" value="<?php echo register_htmlencode($form_token); ?>">
            <input type="hidden" name="return_path" value="<?php echo register_htmlencode($return_path); ?>">
        </form>
        <a class="public-auth-mode-link" href="<?php echo register_htmlencode($method_urls['password']); ?>" data-public-auth-mode-open="password" data-register-native-navigation><?php echo $trans('Use a password'); ?></a>
    </section>
    <?php endif; ?>
    <section class="public-auth-mode-panel" data-public-auth-mode-panel="password" data-public-auth-heading="<?php echo register_htmlencode($headings['password']); ?>"<?php if ($mode !== 'password'): ?> hidden<?php endif; ?>>
        <form class="public-auth-form public-auth-password-form" method="post" action="<?php echo register_htmlencode($password_url); ?>" data-public-auth-form="password" data-busy-label="<?php echo register_htmlencode($trans('Signing in')); ?>">
            <label class="public-auth-field">
                <span><?php echo $trans('Login'); ?></span>
                <input type="text" name="login" autocomplete="username" autocapitalize="none" spellcheck="false" required>
            </label>
            <label class="public-auth-field">
                <span><?php echo $trans('Password'); ?></span>
                <span class="public-auth-password-input">
                    <input type="password" name="pass" autocomplete="current-password" required>
                    <button class="public-auth-password-toggle" type="button" data-public-auth-password-toggle data-show-label="<?php echo register_htmlencode($trans('Show password')); ?>" data-hide-label="<?php echo register_htmlencode($trans('Hide password')); ?>" aria-label="<?php echo register_htmlencode($trans('Show password')); ?>" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </button>
                </span>
            </label>
            <label class="public-auth-remember"><input type="checkbox" name="remember_me" value="1" checked><span><?php echo $trans('Remember me'); ?></span></label>
            <input type="hidden" name="auth_token" value="<?php echo register_htmlencode($form_token); ?>">
            <input type="hidden" name="return_path" value="<?php echo register_htmlencode($return_path); ?>">
            <button class="public-auth-primary" type="submit"><?php echo $trans('Sign in'); ?></button>
        </form>
        <?php if ($email_enabled): ?>
        <a class="public-auth-mode-link" href="<?php echo register_htmlencode($method_urls['email']); ?>" data-public-auth-mode-open="email" data-register-native-navigation><?php echo $trans('Use an email link instead'); ?></a>
        <?php endif; ?>
    </section>
</div>
