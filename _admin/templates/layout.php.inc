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
/** @var callable $adminAssetVersion */
/** @var string $menu */
/** @var ?string $content */
/** @var ?string $errorMessage */
/** @var array $flashMessages */
/** @var array $extraStyles */
/** @var array $extraScripts */

?>
<!DOCTYPE html>
<html lang="<?= $locale ?>" data-color-scheme="<?= register_htmlencode($styleColorScheme()) ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo $trans('Admin panel'), $param('REGISTER_SITE_NAME') ? ' - ' . $param('REGISTER_SITE_NAME') : ''; ?></title>
    <meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="<?= $basePath ?>/_styles/register/favicon.svg">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/_assets/register/admin-yard/style.css">
    <link rel="stylesheet" href="<?= $basePath ?>/_admin/css/admin-override.css?v=<?= $adminAssetVersion('_admin/css/admin-override.css') ?>">
    <link rel="stylesheet" href="<?= $basePath ?>/_admin/css/register.css?v=<?= $adminStyleVersion() ?>">
    <link rel="stylesheet" href="<?= $basePath ?>/_assets/register/local-time.css">
    <link id="admin-theme-stylesheet" rel="stylesheet" href="<?= $basePath ?>/_admin/index.php?action=theme-stylesheet">
    <?php foreach ($extraStyles as $style): ?>
        <link rel="stylesheet" href="<?= $style ?>">
    <?php endforeach; ?>

    <script src="<?= $basePath ?>/_assets/register/local-time.js"></script>
    <script src="<?php echo $basePath; ?>/_assets/register/admin-yard/script.js"></script>
    <script src="<?= $basePath ?>/_admin/js/lib.js?v=<?= $adminAssetVersion('_admin/js/lib.js') ?>"></script>
    <?php foreach ($extraScripts as $script): ?>
        <script src="<?= $script ?>"></script>
    <?php endforeach; ?>

    <script src="<?php echo $basePath; ?>/_admin/lang/<?php echo $locale; ?>/ui.js?v=<?= $adminAssetVersion('_admin/lang/' . $locale . '/ui.js') ?>"></script>
</head>
<body>
<a class="admin-skip-link" href="#admin-content"><?= $trans('Skip to content') ?></a>
<div id="loading" class="loading-indicator" aria-hidden="true"></div>
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
        <div class="flash-message flash-<?= $type ?>" role="status">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="flash-message-close" aria-label="<?= $trans('Close') ?>">&times;</button>
        </div>
        <?php
    }
}
?>
<?php if ($content === null && isset($errorMessage)): ?>
    <main id="admin-content" class="error" tabindex="-1">
        <h1><?= $trans('An error encountered') ?></h1>
        <div class="error-message-box"><p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p></div>
    </main>
<?php else: ?>
    <main id="admin-content" class="admin-content" tabindex="-1"><?= $content ?></main>
<?php endif; ?>
    <?php require __DIR__ . '/confirm-dialog.php.inc'; ?>
    <dialog id="error-dialog" aria-labelledby="error-dialog-title">
        <h1 id="error-dialog-title"><?= $trans('An error encountered') ?></h1>
        <p><iframe id="error-iframe" src="about:blank" title="<?= $trans('An error encountered') ?>"></iframe></p>
        <button class="primary" id="error-dialog-close"><?= $trans('Close') ?></button>
    </dialog>
</body>
</html>
