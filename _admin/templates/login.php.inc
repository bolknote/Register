<?php

/** @var callable $trans */
/** @var callable $param */
/** @var callable $styleColorScheme */
/** @var callable $adminStyleVersion */
/** @var string $locale */
/** @var string $basePath */
/** @var string $errorMessage */

?>
<!DOCTYPE html>
<html lang="<?= $locale ?>" data-color-scheme="<?= s2_htmlencode($styleColorScheme()) ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo $trans('Admin panel'), $param('S2_SITE_NAME') ? ' - ' . $param('S2_SITE_NAME') : ''; ?></title>
    <meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="<?= $basePath ?>/_styles/register/favicon.svg">
    <link rel="stylesheet" href="<?= $basePath ?>/_admin/css/admin-override.css">
    <link rel="stylesheet" href="<?= $basePath ?>/_admin/css/register.css?v=<?= $adminStyleVersion() ?>">
    <script src="<?= $basePath ?>/_admin/js/lib.js"></script>
    <script src="<?= $basePath ?>/_admin/js/webauthn.js" defer></script>
</head>
<body class="login_page" data-webauthn-messages
      data-webauthn-operation-failed="<?= s2_htmlencode($trans('The security operation failed.')) ?>"
      data-webauthn-verification-failed="<?= s2_htmlencode($trans('The passkey could not be verified.')) ?>"
      data-webauthn-no-passkey="<?= s2_htmlencode($trans('No passkey was selected.')) ?>"
      data-webauthn-creation-failed="<?= s2_htmlencode($trans('The passkey was not created.')) ?>">
<main id="login_wrap">
    <h1 class="login-heading">
        <a class="login-brand" href="<?= $basePath ?>/" title="<?= $trans('Open site') ?>">
            <span class="admin-brand-mark" aria-hidden="true">ℜ</span>
            <span>Register</span>
        </a>
    </h1>
    <noscript><p><?php echo $trans('Noscript'); ?></p></noscript>
    <form name="loginform" class="loginform" method="post" action="">
        <p>
            <label>
                <span><?php echo $trans('Login'); ?></span>
                <input type="text" name="login" size="30" maxlength="255" autocomplete="username" autocapitalize="none" required autofocus>
            </label>
            <label>
                <span><?php echo $trans('Password'); ?></span>
                <input type="password" name="pass" size="30" maxlength="255" autocomplete="current-password" required disabled="disabled">
            </label>
        </p>
        <p class="login-session-options">
            <label class="login-session-option">
                <input type="checkbox" name="remember_me" value="1">
                <span><?php echo $trans('Remember me'); ?></span>
            </label>
        </p>
        <p class="login-actions">
            <input type="submit" name="button" value="<?php echo $trans('Log in'); ?>"/>
            <button type="button" data-webauthn-login><?php echo $trans('Sign in with a passkey'); ?></button>
        </p>
    </form>
    <details class="login-recovery">
        <summary><?php echo $trans('Use a recovery code'); ?></summary>
        <form method="post" action="?action=webauthn_recovery_login" data-webauthn-recovery-login>
            <label>
                <span><?php echo $trans('Login'); ?></span>
                <input type="text" name="login" maxlength="255" autocomplete="username" required>
            </label>
            <label>
                <span><?php echo $trans('Recovery code'); ?></span>
                <input type="text" name="recovery_code" maxlength="30" autocomplete="one-time-code" required>
            </label>
            <input type="hidden" name="remember_me" value="0">
            <button type="submit"><?php echo $trans('Sign in'); ?></button>
        </form>
    </details>
    <p id="message" class="login-message" role="alert" aria-live="polite"<?= $errorMessage === '' ? ' hidden' : '' ?>><?= htmlentities($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
</main>
</body>
</html>
