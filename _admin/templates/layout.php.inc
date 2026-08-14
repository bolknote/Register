<?php

declare(strict_types=1);

if (!isset($this)) {
    die;
}

/** @var string $locale */
/** @var string $basePath */
/** @var callable $trans */
/** @var callable $param */
/** @var callable $styleColorScheme */
/** @var callable $adminStyleVersion */
/** @var string $menu */
/** @var ?string $content */
/** @var ?string $errorMessage */
/** @var array $flashMessages */
/** @var array $extraStyles */
/** @var array $extraScripts */

?>
<!DOCTYPE html>
<html lang="<?= $locale ?>" data-color-scheme="<?= s2_htmlencode($styleColorScheme()) ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo $trans('Admin panel'), $param('S2_SITE_NAME') ? ' - ' . $param('S2_SITE_NAME') : ''; ?></title>
    <meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="<?= $basePath ?>/_styles/register/favicon.svg">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/_vendor/s2/admin-yard/demo/style.css">
    <link rel="stylesheet" href="<?= $basePath ?>/_admin/css/admin-override.css">
    <link rel="stylesheet" href="<?= $basePath ?>/_admin/css/register.css?v=<?= $adminStyleVersion() ?>">
    <?php foreach ($extraStyles as $style): ?>
        <link rel="stylesheet" href="<?= $style ?>">
    <?php endforeach; ?>

    <script src="<?php echo $basePath; ?>/_vendor/s2/admin-yard/demo/script.js"></script>
    <script src="<?= $basePath ?>/_admin/js/lib.js"></script>
    <?php foreach ($extraScripts as $script): ?>
        <script src="<?= $script ?>"></script>
    <?php endforeach; ?>

    <script src="<?php echo $basePath; ?>/_admin/lang/<?php echo $locale; ?>/ui.js"></script>
    <style>:root {
            --page-secondary-background: <?= $param('S2_ADMIN_COLOR') ?>;
        }</style>
</head>
<body>
<div id="loading" class="loading-indicator"></div>
<header class="admin-shell">
    <a class="admin-brand" href="<?= $basePath ?>/" title="<?= $trans('Open site') ?>">
        <span class="admin-brand-mark" aria-hidden="true">ℜ</span>
        <span>Register</span>
    </a>
    <nav aria-label="<?= $trans('Admin navigation') ?>">
        <?= $menu ?>
    </nav>
</header>
<?php
foreach ($flashMessages as $type => $messages) {
    foreach ($messages as $message) {
        ?>
        <div class="flash-message flash-<?= $type ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="flash-message-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php
    }
}
?>
<?php if ($content === null && isset($errorMessage)): ?>
    <article class="error">
        <h1><?= $trans('An error encountered') ?></h1>
        <div class="error-message-box"><p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p></div>
    </article>
<?php else: ?>
    <article class="admin-content"><?= $content ?></article>
<?php endif; ?>
    <dialog id="error-dialog">
        <h1><?= $trans('An error encountered') ?></h1>
        <p><iframe id="error-iframe" src="about:blank"></iframe></p>
        <button class="primary" id="error-dialog-close"><?= $trans('Close') ?></button>
    </dialog>
</body>
</html>
