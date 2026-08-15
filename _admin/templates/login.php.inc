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
</head>
<body class="login_page">
<div id="login_wrap">
    <a class="login-brand" href="<?= $basePath ?>/" title="<?= $trans('Open site') ?>">
        <span class="admin-brand-mark" aria-hidden="true">ℜ</span>
        <span>Register</span>
    </a>
    <noscript><p><?php echo $trans('Noscript'); ?></p></noscript>
    <form name="loginform" class="loginform" method="post" action="">
        <p>
            <label>
                <span><?php echo $trans('Login'); ?></span>
                <input type="text" name="login" size="30" maxlength="255">
            </label>
            <label>
                <span><?php echo $trans('Password'); ?></span>
                <input type="password" name="pass" size="30" maxlength="255" disabled="disabled">
            </label>
        </p>
        <p class="login-session-options">
            <label class="login-session-option">
                <input type="checkbox" name="remember_me" value="1">
                <span><?php echo $trans('Remember me'); ?></span>
            </label>
        </p>
        <p>
            <input type="submit" name="button" value="<?php echo $trans('Log in'); ?>"/>
        </p>
    </form>
    <p id="message" class="message"><?= htmlentities($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
</div>
</body>
</html>
