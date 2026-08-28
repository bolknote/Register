<?php

declare(strict_types = 1);

/**
 * Loads common data and performs various functions necessary for the site to work properly.
 *
 * @copyright 2009-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

use Psr\Log\LoggerInterface;
use Register\Backup\BackupDirectoryResolver;
use Register\Core\Http\ContentSecurityPolicy;
use Register\Module\BaseModuleRegistry;
use Register\RegisterKernel;
use Register\Schema\SchemaManager;
use Register\Update\BuildInfo;
use Register\Update\MaintenanceMode;
use Register\Update\RuntimeLock;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Config\MediaStorageConfigResolver;
use Register\Core\Config\SecretConfigPathResolver;
use Register\Core\Config\StaticConfigLoader;
use Register\Core\Framework\Application;
use Register\Core\Framework\Exception\ConfigurationException;
use Register\Core\Framework\Exception\ParameterNotFoundException;
use Register\Core\Framework\ModuleInterface;
use Register\Core\Model\ExtensionCache;
use Register\Core\Queue\ShutdownWorkCoordinator;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;

$registerBootTimestamp = microtime(true);

// Uncomment these lines for debug
//define('REGISTER_DEBUG', 1);
//define('REGISTER_SHOW_QUERIES', 1);

require __DIR__ . '/../_vendor/autoload.php';

$registerApplicationRoot = dirname(__DIR__);
define('REGISTER_VERSION', BuildInfo::version($registerApplicationRoot));
$registerUpdateRequest = MaintenanceMode::isUpdateRequest($_SERVER, $_GET, $_POST);
(new MaintenanceMode($registerApplicationRoot))->enforce($registerUpdateRequest);
$registerRuntimeLock = $registerUpdateRequest ? null : RuntimeLock::acquireShared($registerApplicationRoot);

$staticConfigLoader = new StaticConfigLoader();
$registerStaticConfig     = $staticConfigLoader->load(__DIR__ . '/../' . register_get_config_filename());

$debugEnabled = defined('REGISTER_DEBUG') || ($registerStaticConfig['options']['debug'] ?? false) === true;
error_reporting($debugEnabled ? E_ALL : E_ALL ^ E_NOTICE);

require __DIR__ . '/../_include/setup.php';

if ($debugEnabled) {
    $errorHandler = Debug::enable();
} else {
    $errorHandler = ErrorHandler::register();
}

HtmlErrorRenderer::setTemplate(__DIR__ . '/views/error.php');

$registerBaseStaticParameters = register_build_base_static_parameters($registerStaticConfig);

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function register_build_base_static_parameters(array $config): array
{
    $rootDir       = dirname(__DIR__) . '/';
    $publicRootDir = \defined('REGISTER_PUBLIC_ROOT')
        ? rtrim((string)\constant('REGISTER_PUBLIC_ROOT'), '/\\') . '/'
        : $rootDir;

    $filesConfig = \is_array($config['files'] ?? null) ? $config['files'] : [];
    $cacheDir = isset($filesConfig['cache_dir']) && \is_string($filesConfig['cache_dir'])
        ? rtrim($filesConfig['cache_dir'], '/') . '/'
        : register_get_default_cache_dir();

    $logDir = isset($filesConfig['log_dir']) && \is_string($filesConfig['log_dir'])
        ? rtrim($filesConfig['log_dir'], '/') . '/'
        : $cacheDir;

    $basePath = $config['http']['base_path'] ?? null;
    $mediaStorage = MediaStorageConfigResolver::resolve(
        $publicRootDir,
        isset($filesConfig['image_dir']) && is_string($filesConfig['image_dir'])
            ? $filesConfig['image_dir']
            : null,
        isset($filesConfig['image_url']) && is_string($filesConfig['image_url'])
            ? $filesConfig['image_url']
            : null,
        \is_string($basePath) ? $basePath : null,
    );

    $baseUrl   = $config['http']['base_url'] ?? null;
    $urlPrefix = $config['http']['url_prefix'] ?? '';

    $debug           = (bool)($config['options']['debug'] ?? false);
    $debugView       = (bool)($config['options']['debug_view'] ?? false);
    $showQueries     = (bool)($config['options']['show_queries'] ?? false);
    $disableCache    = (bool)($config['options']['disable_cache'] ?? false);
    $forceAdminHttps = (bool)($config['options']['force_admin_https'] ?? false);
    $canonicalUrl    = $config['options']['canonical_url'] ?? null;

    return [
        'root_dir'           => $rootDir,
        'public_root_dir'    => $publicRootDir,
        'cache_dir'          => $cacheDir,
        'allowed_extensions' => $filesConfig['allowed_extensions'] ?? StaticConfigLoader::DEFAULT_ALLOWED_EXTENSIONS,
        'upload_quota_bytes' => $filesConfig['upload_quota_bytes'] ?? StaticConfigLoader::DEFAULT_UPLOAD_QUOTA_BYTES,
        'image_dir'          => $mediaStorage['directory'], // no trailing '/' for Filesystem component
        'image_path'         => $mediaStorage['url'],
        'content_image_directory' => $filesConfig['content_image_directory'] ?? '',
        'disable_cache'      => $disableCache,
        'log_dir'            => $logDir,

        // full prefix for absolute web URLs, i.e. main page URL supposed to be BASE_URL . URL_PREFIX . '/'
        'base_url'           => $baseUrl,

        // path prefix for the web URL, i.e. main page URL supposed to be 'http://example.com' . BASE_PATH . URL_PREFIX . '/'
        'base_path'          => $basePath,
        'trusted_proxies'    => $config['http']['trusted_proxies'] ?? [],

        // one of '', '/?', '/index.php', '/index.php?'
        'url_prefix'         => $urlPrefix,
        'debug'              => $debug,
        'debug_view'         => $debugView,
        'show_queries'       => $showQueries,
        'force_admin_https'  => $forceAdminHttps,
        'canonical_url'      => $canonicalUrl,
        'version'            => REGISTER_VERSION,
        'redirect_map'       => $config['redirects'] ?? [],
        'cookie_name'        => $config['cookies']['name'] ?? StaticConfigLoader::DEFAULT_COOKIE_NAME,
        'antispam_secret'    => $config['security']['antispam_secret'] ?? null,
        'secret_config_file' => SecretConfigPathResolver::resolve(
            $rootDir,
            $publicRootDir,
            isset($config['security']['secret_file']) && is_string($config['security']['secret_file'])
                ? $config['security']['secret_file']
                : null,
        ),
        'backup_enabled'     => $config['backups']['enabled'] ?? true,
        'backup_dir'         => BackupDirectoryResolver::resolve(
            $rootDir,
            isset($config['backups']['directory']) && is_string($config['backups']['directory'])
                ? $config['backups']['directory']
                : null,
        ),
        'backup_retention'   => $config['backups']['retention'] ?? 7,
        'backup_encryption_key' => $config['backups']['encryption_key'] ?? null,
        'backup_recipient_public_key' => $config['backups']['recipient_public_key'] ?? null,
        'db_type'            => $config['database']['type'] ?? null,
        'db_host'            => $config['database']['host'] ?? null,
        'db_name'            => $config['database']['name'] ?? null,
        'db_username'        => $config['database']['user'] ?? null,
        'db_password'        => $config['database']['password'] ?? null,
        'db_prefix'          => $config['database']['prefix'] ?? null,
        'p_connect'          => $config['database']['p_connect'] ?? false,
    ];
}

/** @return array<string, mixed> */
function collectParameters(): array
{
    global $registerBootTimestamp, $registerBaseStaticParameters;

    $result                   = $registerBaseStaticParameters;
    $result['boot_timestamp'] = $registerBootTimestamp;

    return $result;
}

$app                = new Application();
$baseModuleRegistry = new BaseModuleRegistry();
(new RegisterKernel($baseModuleRegistry))->registerBaseModules($app, defined('REGISTER_ADMIN_MODE'));

$enabledExtensions = null;
$cacheDir          = (string)$registerBaseStaticParameters['cache_dir'];
$disableCache      = (bool)$registerBaseStaticParameters['disable_cache'];
if (!$disableCache && file_exists($cacheDir . ExtensionCache::CACHE_ENABLED_EXTENSIONS_FILENAME)) {
    $enabledExtensions = include $cacheDir . ExtensionCache::CACHE_ENABLED_EXTENSIONS_FILENAME;
}

try {
    if (!is_array($enabledExtensions)) {
        $app->boot(collectParameters());
        $appCache          = $app->container->get(ExtensionCache::class);
        $enabledExtensions = $appCache->generateEnabledExtensionClassNames($baseModuleRegistry->ids());
    }

    $staticallyLoadedClasses = array_merge(
        $baseModuleRegistry->applicationModuleClasses(),
        $baseModuleRegistry->adminModuleClasses()
    );

    foreach ($enabledExtensions['cms'] as $module) {
        if (is_string($module) && in_array(ltrim($module, '\\'), $staticallyLoadedClasses, true)) {
            continue;
        }

        if (!is_string($module) || !is_a($module, ModuleInterface::class, true)) {
            throw new ConfigurationException('The enabled CMS extension cache contains an invalid class name.');
        }

        $app->addModule(new $module());
    }

    if (defined('REGISTER_ADMIN_MODE')) {
        foreach ($enabledExtensions['admin'] as $module) {
            if (is_string($module) && in_array(ltrim($module, '\\'), $staticallyLoadedClasses, true)) {
                continue;
            }

            if (!is_string($module) || !is_a($module, ModuleInterface::class, true)) {
                throw new ConfigurationException('The enabled admin extension cache contains an invalid class name.');
            }

            $app->addModule(new $module());
        }
    }

    $app->boot(collectParameters());
    $appCache = $app->container->get(ExtensionCache::class);
    if (!$disableCache) {
        $app->setCachedRoutesFilename($appCache->getCachedRoutesFilename());
    }

    $app->container->getParameter('base_url');
} catch (ParameterNotFoundException $e) {
    // Register is not installed
    ContentSecurityPolicy::send();
    $configFilename   = register_get_config_filename();
    $installationPath = substr(
            dirname(__DIR__),
            str_ends_with($_SERVER['SCRIPT_FILENAME'], $_SERVER['SCRIPT_NAME'])
                ? strlen($_SERVER['SCRIPT_FILENAME']) - strlen($_SERVER['SCRIPT_NAME'])
                : strlen($_SERVER['DOCUMENT_ROOT'] ?? '')
        ) . '/_admin/install.php';
    require __DIR__ . '/installation_required.php';

    exit;
}

$errorHandler->setDefaultLogger($app->container->get(LoggerInterface::class));

$dynamicConfigProvider = $app->container->get(DynamicConfigProvider::class);

if (defined('REGISTER_ADMIN_MODE') && session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.gc_maxlifetime', (string)\Register\Core\Model\AuthManager::PERSISTENT_SESSION_LIFETIME);
    ini_set('session.cookie_httponly', true);
}

$registerSchemaManager = $app->container->get(SchemaManager::class);
if (!$registerUpdateRequest && $registerSchemaManager->ensureCurrent()) {
    $dynamicConfigProvider->regenerate();
}

if (!$registerUpdateRequest) {
    $app->container->get(ShutdownWorkCoordinator::class)->register();
}

return $app;
