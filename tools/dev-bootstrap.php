<?php

declare(strict_types = 1);

use S2\Cms\Model\Installer;
use S2\Cms\Pdo\DbLayerSqlite;
use S2\Cms\Pdo\PdoSqliteFactory;

const S2_DEV_REQUIRED_EXTENSIONS = [
    'dom',
    'gd',
    'mbstring',
    'pdo_sqlite',
];

const REGISTER_DEV_WELCOME = <<<'HTML'
<h1>Welcome to Register</h1>
<p>Register is a small, fast engine for a personal blog: a place for posts, permanent pages, tags, archives, RSS, and thoughtful discussion without a noisy public interface.</p>
<h2>What is ready</h2>
<ul><li>Drafts and publication;</li><li>comments and moderation;</li><li>images, tags, favorites, and search extensions;</li><li>multiple authors and clear permissions;</li><li>a responsive light and dark reading theme.</li></ul>
<h2>Start here</h2>
<ol><li>Open the control panel using the lock in the footer.</li><li>Give the blog its name in Configuration.</li><li>Edit or delete this note and publish the first post.</li></ol>
<p>This welcome note is only starting material. Register is a blog engine, not a universal site builder; its job is to keep writing, publishing, and reading pleasantly direct.</p>
HTML;

$rootDir = dirname(__DIR__);
require $rootDir . '/_vendor/autoload.php';

if (PHP_VERSION_ID < 80300) {
    throw new RuntimeException(sprintf('Register requires PHP 8.3 or newer; %s is running.', PHP_VERSION));
}

foreach (S2_DEV_REQUIRED_EXTENSIONS as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException(sprintf('The required PHP extension "%s" is not loaded.', $extension));
    }
}

$host = getenv('S2_DEV_HOST');
$port = getenv('S2_DEV_PORT');
$host = is_string($host) && $host !== '' ? $host : '127.0.0.1';
$port = is_string($port) && $port !== '' ? $port : '8080';

if (preg_match('/^(?:localhost|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\d{1,3}(?:\.\d{1,3}){3})$/iD', $host) !== 1) {
    throw new RuntimeException(sprintf('Invalid S2_DEV_HOST value "%s".', $host));
}

if (filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
    throw new RuntimeException(sprintf('Invalid S2_DEV_PORT value "%s".', $port));
}

$localDir    = $rootDir . '/.local';
$database    = $localDir . '/s2.sqlite';
$baseUrl     = sprintf('http://%s:%s', $host, $port);
$configFile  = $rootDir . '/config.local.php';
$adminLogin  = getenv('S2_DEV_ADMIN_LOGIN');
$adminPass   = getenv('S2_DEV_ADMIN_PASSWORD');
$adminLogin  = is_string($adminLogin) && $adminLogin !== '' ? $adminLogin : 'admin';
$adminPass   = is_string($adminPass) && $adminPass !== '' ? $adminPass : 'admin';

foreach ([$localDir, $rootDir . '/_cache/local', $rootDir . '/_pictures'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create local directory "%s".', $directory));
    }
}

$config = [
    'database' => [
        'type'      => 'sqlite',
        'host'      => '',
        'name'      => '.local/s2.sqlite',
        'user'      => '',
        'password'  => '',
        'prefix'    => '',
        'p_connect' => false,
    ],
    'http' => [
        'base_url'   => $baseUrl,
        'base_path'  => '',
        'url_prefix' => '',
    ],
    'options' => [
        'force_admin_https' => false,
        'canonical_url'     => null,
        'disable_cache'     => false,
        'debug'             => true,
        'debug_view'        => false,
        'show_queries'      => false,
    ],
    'cookies' => [
        'name' => 's2_local_' . substr(hash('sha256', $rootDir), 0, 16),
    ],
];

$configContent = "<?php\n\ndeclare(strict_types = 1);\n\nreturn " . var_export($config, true) . ";\n";
if (file_put_contents($configFile, $configContent, LOCK_EX) === false) {
    throw new RuntimeException(sprintf('Unable to write local config "%s".', $configFile));
}

$pdo     = PdoSqliteFactory::create($database, false);
$dbLayer = new DbLayerSqlite($pdo);
$isNew   = !$dbLayer->tableExists('config');

if ($isNew) {
    $dbLayer->startTransaction();

    try {
        $installer = new Installer($dbLayer);
        $installer->createTables();

        $dbLayer
            ->insert('users')
            ->setValue('login', ':login')->setParameter('login', $adminLogin)
            ->setValue('password', ':password')->setParameter('password', password_hash($adminPass, PASSWORD_DEFAULT))
            ->setValue('email', ':email')->setParameter('email', 'admin@example.test')
            ->setValue('view', '1')
            ->setValue('view_hidden', '1')
            ->setValue('hide_comments', '1')
            ->setValue('edit_comments', '1')
            ->setValue('create_articles', '1')
            ->setValue('edit_site', '1')
            ->setValue('edit_users', '1')
            ->execute()
        ;

        $installer->insertConfigData('Register', 'admin@example.test', 'English', Installer::DB_REVISION);
        $installer->insertMainPage('Register', time(), REGISTER_DEV_WELCOME);
        $dbLayer->endTransaction();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }
}

echo PHP_EOL;
echo sprintf("Register: %s/\n", $baseUrl);
echo sprintf("Admin:    %s/_admin/index.php\n", $baseUrl);
if ($isNew) {
    echo sprintf("Login:    %s\n", $adminLogin);
    echo sprintf("Password: %s\n", $adminPass);
}

echo "Press Ctrl+C to stop the server.\n\n";
