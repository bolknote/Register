<?php

declare(strict_types = 1);

// A real installation in disposable storage; no developer or production database is opened.
require dirname(__DIR__, 3) . '/_vendor/autoload.php';

use Register\Core\Framework\Application;
use Register\Core\Pdo\DbLayer;
use Register\Model\Installer;
use Register\Module\BaseModuleRegistry;
use Register\RegisterKernel;
use Register\Schema\SchemaManager;

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    throw new RuntimeException('Pass the disposable installation settings to this CLI fixture.');
}

$rawSettings = file_get_contents($argv[1]);
if ($rawSettings === false) {
    throw new RuntimeException('Unable to read the disposable installation settings.');
}

$settings = json_decode($rawSettings, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($settings)) {
    throw new RuntimeException('Installation settings must be an object.');
}

foreach (['scratch', 'database', 'origin', 'id', 'config'] as $key) {
    if (!isset($settings[$key]) || !is_string($settings[$key])) {
        throw new RuntimeException('Missing installation setting: ' . $key);
    }
}

$root = dirname(__DIR__, 3) . '/';
$scratch = $settings['scratch'];
foreach (['cache', 'media', 'sessions'] as $directory) {
    mkdir($scratch . '/' . $directory, 0700, true);
}

$parameters = [
    'root_dir' => $root, 'public_root_dir' => $root,
    'cache_dir' => $scratch . '/cache/', 'log_dir' => $scratch . '/cache/',
    'image_dir' => $scratch . '/media', 'image_path' => '/_e2e_media',
    'content_image_directory' => '', 'allowed_extensions' => 'png jpg jpeg webp mp3',
    'upload_quota_bytes' => 1024 * 1024 * 1024,
    // UI regressions must use current assets, not a developer's previously
    // compiled public bundle in the shared _cache directory.
    'disable_cache' => true, 'base_url' => $settings['origin'], 'base_path' => '',
    'url_prefix' => '', 'trusted_proxies' => [], 'debug' => false,
    'debug_view' => false, 'show_queries' => false, 'boot_timestamp' => microtime(true),
    'redirect_map' => [], 'version' => 'e2e', 'canonical_url' => null,
    'cookie_name' => 'register_e2e_' . $settings['id'],
    'antispam_secret' => str_repeat('ab', 32), 'secret_config_file' => $scratch . '/secrets.php',
    'backup_enabled' => false, 'backup_dir' => $scratch . '/backups', 'backup_retention' => 2,
    'backup_encryption_key' => '', 'backup_recipient_public_key' => null,
    'force_admin_https' => false, 'db_host' => '', 'db_name' => $settings['database'],
    'db_prefix' => '', 'p_connect' => false, 'db_type' => 'sqlite',
    'db_username' => '', 'db_password' => '',
];
$application = new Application();
(new RegisterKernel(new BaseModuleRegistry()))->registerBaseModules($application, true);
$application->boot($parameters);
$db = $application->container->get(DbLayer::class);
$installer = new Installer($db);
$installer->createTables();
$installer->insertConfigData('Editor E2E', 'editor@example.test', 'English');
$pageId = $installer->insertMainPage('Server page', time(), '<p>Server page body.</p>');
foreach (['editor', 'other'] as $login) {
    $db->insert('users')->values([
        'login' => ':login', 'password' => ':password', 'email' => ':email',
        'view' => '1', 'view_hidden' => '1', 'hide_comments' => '1',
        'edit_comments' => '1', 'create_articles' => '1', 'edit_site' => '1', 'edit_users' => '1',
    ])->execute(['login' => $login, 'password' => password_hash($login . '-password', PASSWORD_DEFAULT), 'email' => $login . '@example.test']);
}

$application->container->get(SchemaManager::class)->ensureCurrent();

$config = [
    'database' => ['type' => 'sqlite', 'name' => $parameters['db_name'], 'host' => '', 'user' => '', 'password' => '', 'prefix' => ''],
    'http' => ['base_url' => $settings['origin'], 'base_path' => '', 'url_prefix' => ''],
    'options' => ['force_admin_https' => false, 'disable_cache' => true],
    'files' => ['cache_dir' => $parameters['cache_dir'], 'log_dir' => $parameters['log_dir'],
        'image_dir' => $parameters['image_dir'], 'image_url' => $parameters['image_path']],
    'cookies' => ['name' => $parameters['cookie_name']],
    'security' => ['antispam_secret' => $parameters['antispam_secret'], 'secret_file' => $parameters['secret_config_file']],
    'backups' => ['enabled' => false],
];
file_put_contents($settings['config'], "<?php\nreturn " . var_export($config, true) . ";\n");
echo json_encode(['pageId' => $pageId], JSON_THROW_ON_ERROR);
