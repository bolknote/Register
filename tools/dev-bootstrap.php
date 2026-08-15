<?php

declare(strict_types = 1);

use Register\Installation\WelcomePostInstaller;
use Register\Module\BaseModuleInstaller;
use Register\Module\BaseModuleRegistry;
use Register\RegisterKernel;
use Register\Schema\SchemaManager;
use Register\Module\Search\SearchIndexRebuilder;
use S2\Cms\Config\StaticConfigLoader;
use S2\Cms\Framework\Application;
use S2\Cms\Framework\Container;
use S2\Cms\Model\Installer;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Pdo\DbLayerSqlite;
use S2\Cms\Pdo\PdoSqliteFactory;
use S2\Rose\Storage\Database\PdoStorage;

const S2_DEV_REQUIRED_EXTENSIONS = [
    'dom',
    'gd',
    'mbstring',
    'pdo_sqlite',
];

const REGISTER_DEV_WELCOME = <<<'HTML'
<p>Register is a small, fast engine for a personal blog: a place for posts, permanent pages, tags, archives, RSS, and thoughtful discussion without a noisy public interface.</p>
<h2>What is ready</h2>
<ul><li>Drafts and publication;</li><li>threaded comments and moderation;</li><li>images, tags, favorites, built-in search, and optional modules;</li><li>multiple authors and clear permissions;</li><li>a responsive editorial reading theme.</li></ul>
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
$host = is_string($host) && $host !== '' ? $host : 'localhost';
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

foreach ([$localDir => 0700, $rootDir . '/_cache/local' => 0700, $rootDir . '/_pictures' => 0755] as $directory => $mode) {
    if (!is_dir($directory) && !mkdir($directory, $mode, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create local directory "%s".', $directory));
    }

    chmod($directory, $mode);
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
        'trusted_proxies' => [],
    ],
    'options' => [
        'force_admin_https' => false,
        'canonical_url'     => null,
        'disable_cache'     => false,
        'debug'             => true,
        'debug_view'        => false,
        'show_queries'      => false,
    ],
    'files' => [
        'upload_quota_bytes' => StaticConfigLoader::DEFAULT_UPLOAD_QUOTA_BYTES,
    ],
    'cookies' => [
        'name' => 's2_local_' . substr(hash('sha256', $rootDir), 0, 16),
    ],
    'security' => [
        'antispam_secret' => hash('sha256', 'register-local-antispam:' . $rootDir),
        'secret_file'     => '.local/config.secrets.php',
    ],
    'backups' => [
        'enabled'        => true,
        'directory'      => '.local/backups',
        'retention'      => 3,
        'encryption_key' => hash('sha256', 'register-local-backup:' . $rootDir),
    ],
];

$configContent = "<?php\n\ndeclare(strict_types = 1);\n\nreturn " . var_export($config, true) . ";\n";
if (file_put_contents($configFile, $configContent, LOCK_EX) === false) {
    throw new RuntimeException(sprintf('Unable to write local config "%s".', $configFile));
}

$pdo     = PdoSqliteFactory::create($database, false);
$dbLayer = new DbLayerSqlite($pdo);
$isNew   = !$dbLayer->tableExists('config');

if (!$isNew) {
    $storedGeneration = $dbLayer
        ->select('value')
        ->from('config')
        ->where('name = :name')->setParameter('name', SchemaManager::CONFIG_KEY)
        ->execute()
        ->result()
    ;
    $validGeneration = \is_string($storedGeneration)
        && preg_match('/^(?:0|[1-9][0-9]*)$/D', $storedGeneration) === 1;

    if (!$validGeneration || (int)$storedGeneration !== SchemaManager::CURRENT_GENERATION) {
        $backup = $database . '.incompatible-' . date('Ymd-His') . '.bak';
        unset($dbLayer, $pdo);

        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            $source = $database . $suffix;
            if (is_file($source) && !rename($source, $backup . $suffix)) {
                throw new RuntimeException(sprintf('Unable to back up incompatible local database file "%s".', $source));
            }
        }

        echo sprintf(
            "Rebuilding the incompatible local database (schema generation %s, backup: %s).\n",
            is_scalar($storedGeneration) ? (string)$storedGeneration : 'missing',
            $backup,
        );

        $pdo     = PdoSqliteFactory::create($database, false);
        $dbLayer = new DbLayerSqlite($pdo);
        $isNew   = true;
    }
}

if ($isNew) {
    $dbLayer->startTransaction();

    try {
        $installer = new Installer($dbLayer);
        $installer->createTables();

        $dbLayer
            ->insert('users')
            ->setValue('login', ':login')->setParameter('login', $adminLogin)
            ->setValue('password', ':password')->setParameter('password', \S2\Cms\Model\PasswordHasher::hash($adminPass))
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
        $adminUserId = (int)$dbLayer->insertId();

        $installer->insertConfigData(
            'Register',
            'admin@example.test',
            'English',
        );
        $moduleContainer = new Container(['db_prefix' => '']);
        $moduleContainer->set(\PDO::class, $pdo);
        $baseModuleRegistry = new BaseModuleRegistry();
        (new SchemaManager(
            $dbLayer,
            $moduleContainer,
            new BaseModuleInstaller($baseModuleRegistry),
        ))->ensureCurrent();

        $now = time();
        $installer->insertMainPage('Register', $now);
        (new WelcomePostInstaller($dbLayer))->create('Welcome to Register', REGISTER_DEV_WELCOME, $adminUserId, $now);
        $dbLayer->endTransaction();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }
}

$application = new Application();
(new RegisterKernel(new BaseModuleRegistry()))->registerBaseModules($application, false);
$application->boot([
    'root_dir'           => $rootDir . '/',
    'public_root_dir'    => $rootDir . '/',
    'cache_dir'          => $rootDir . '/_cache/local/',
    'log_dir'            => $rootDir . '/_cache/local/',
    'base_url'           => $baseUrl,
    'base_path'          => '',
    'trusted_proxies'    => $config['http']['trusted_proxies'],
    'url_prefix'         => '',
    'disable_cache'      => false,
    'debug'              => true,
    'debug_view'         => false,
    'show_queries'       => false,
    'force_admin_https'  => false,
    'canonical_url'      => null,
    'version'            => '2.0dev',
    'redirect_map'       => [],
    'cookie_name'        => $config['cookies']['name'],
    'antispam_secret'    => $config['security']['antispam_secret'],
    'secret_config_file' => $rootDir . '/.local/config.secrets.php',
    'backup_enabled'     => $config['backups']['enabled'],
    'backup_dir'         => $rootDir . '/.local/backups',
    'backup_retention'   => $config['backups']['retention'],
    'backup_encryption_key' => $config['backups']['encryption_key'],
    'db_type'            => $config['database']['type'],
    'db_host'            => $config['database']['host'],
    'db_name'            => $config['database']['name'],
    'db_username'        => $config['database']['user'],
    'db_password'        => $config['database']['password'],
    'db_prefix'          => $config['database']['prefix'],
    'p_connect'          => $config['database']['p_connect'],
    'image_dir'          => $rootDir . '/_pictures',
    'image_path'         => '/_pictures',
    'allowed_extensions' => [],
    'upload_quota_bytes' => $config['files']['upload_quota_bytes'],
    'boot_timestamp'     => microtime(true),
]);
$application->container->get(ExtensionCache::class)->clear();
if ($application->container->get(PdoStorage::class)->getTocSize(null) === 0) {
    $application->container->get(SearchIndexRebuilder::class)->rebuild();
}

echo PHP_EOL;
echo sprintf("Register: %s/\n", $baseUrl);
echo sprintf("Admin:    %s/_admin/index.php\n", $baseUrl);
if ($isNew) {
    echo sprintf("Login:    %s\n", $adminLogin);
    echo sprintf("Password: %s\n", $adminPass);
}

echo "Press Ctrl+C to stop the server.\n\n";
