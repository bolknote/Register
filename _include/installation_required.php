<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 *
 * @var string $installationPath
 * @var string $configFilename
 */

declare(strict_types = 1);

$adminAssetPath = rtrim(str_replace('\\', '/', dirname($installationPath)), '/');
$publicAssetPath = str_replace('\\', '/', dirname($adminAssetPath));
if ($publicAssetPath === '/' || $publicAssetPath === '.') {
    $publicAssetPath = '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register Setup Required</title>
    <link rel="stylesheet" href="<?= s2_htmlencode($publicAssetPath . '/_assets/register/standalone.css') ?>">
</head>
<body class="register-standalone">
<main class="standalone-card setup-card">
    <h1>Register Setup Required <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" aria-label="Warning" role="img">
            <path fill="#ffce31" d="M5.9 62c-3.3 0-4.8-2.4-3.3-5.3L29.3 4.2c1.5-2.9 3.9-2.9 5.4 0l26.7 52.5c1.5 2.9 0 5.3-3.3 5.3H5.9z"/>
            <g fill="#231f20">
                <path d="m27.8 23.6 2.8 18.5c.3 1.8 2.6 1.8 2.9 0l2.7-18.5c.5-7.2-8.9-7.2-8.4 0"/>
                <circle cx="32" cy="49.6" r="4.2"/>
            </g>
        </svg>
    </h1>

    <p>
        <strong>The configuration file (<code><?=$configFilename?></code>) is missing or corrupted.</strong><br>
        This could mean either:
    </p>

    <ul>
        <li>Register hasn't been installed yet, <strong>or</strong></li>
        <li>The config file was accidentally deleted after setup.</li>
    </ul>

    <div class="install-action">
        <a href="<?=$installationPath?>" class="link-button main-button">Run Installation</a>
    </div>

    <div class="help">
        <p><strong>Need help?</strong></p>
        <ul>
            <li>If you already installed Register, restore <code><?=$configFilename?></code> from a backup.</li>
            <li>Or you can create the file manually using <button type="button" class="toggle-config">this template</button></li>
        </ul>

        <div class="config-example" id="configExample">
            <pre><code>&lt;?php

return [
    'database' => [
        'type'      => 'mysql',
        'host'      => '127.0.0.1',
        'name'      => 's2_test',
        'user'      => 'root',
        'password'  => '',
        'prefix'    => '',
        'p_connect' => false,
    ],
    'http' => [
        'base_url'   => 'https://example.com/my_site',
        'base_path'  => '/my_site',
        'url_prefix' => '', // or '/?', '/index.php', '/index.php?'
    ],
    'options' => [
        'force_admin_https' => 0,
        'canonical_url'     => null,
        'debug'             => 0,
        'debug_view'        => 0,
        'show_queries'      => 0,
    ],
    'cookies' => [
        'name' => 's2_cookie_82378103978', // some random string
    ],
];
</code></pre>
        </div>
    </div>
</main>

<script src="<?= s2_htmlencode($adminAssetPath . '/js/installation-required.js') ?>" defer></script>
</body>
</html>
