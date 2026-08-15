<?php

declare(strict_types = 1);

/** @var string $basePath */
/** @var string $locale */
/** @var callable $trans */
/** @var callable $param */
/** @var callable $styleColorScheme */
/** @var callable $adminStyleVersion */
/** @var string $imagePath */

?>
<!DOCTYPE html>
<html lang="<?= s2_htmlencode($locale) ?>" data-color-scheme="<?= s2_htmlencode($styleColorScheme()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= s2_htmlencode($trans('Media')) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= s2_htmlencode($basePath) ?>/_styles/register/favicon.svg">
    <link rel="stylesheet" href="<?= s2_htmlencode($basePath) ?>/_vendor/s2/admin-yard/demo/style.css">
    <link rel="stylesheet" href="<?= s2_htmlencode($basePath) ?>/_admin/css/admin-override.css">
    <link rel="stylesheet" href="<?= s2_htmlencode($basePath) ?>/_admin/css/register.css?v=<?= s2_htmlencode($adminStyleVersion()) ?>">
    <link id="admin-theme-stylesheet" rel="stylesheet" href="<?= s2_htmlencode($basePath) ?>/_admin/index.php?action=theme-stylesheet">
    <script src="<?= s2_htmlencode($basePath) ?>/_vendor/s2/admin-yard/demo/script.js"></script>
    <script src="<?= s2_htmlencode($basePath) ?>/_admin/js/lib.js"></script>
</head>
<body class="picture-manager-standalone">
<header class="picture-manager-header">
    <a class="picture-manager-brand" href="<?= s2_htmlencode($basePath) ?>/_admin/index.php" target="_top">
        <span aria-hidden="true">ℜ</span>
        <span>Register</span>
    </a>
    <strong><?= s2_htmlencode($trans('Media')) ?></strong>
    <a href="<?= s2_htmlencode($basePath) ?>/_admin/index.php" target="_top"><?= s2_htmlencode($trans('Back to administration')) ?></a>
</header>
<?php
$standalone = true;
require __DIR__ . '/picture-manager-content.php.inc';
?>
</body>
</html>
