<?php

declare(strict_types = 1);

/**
 * Installation script for Register.
 *
 * @copyright 2009-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */


use Psr\Log\LogLevel;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Http\ContentSecurityPolicy;
use Register\Installation\WelcomePostInstaller;
use Register\Module\BaseModuleRegistry;
use Register\RegisterKernel;
use Register\Schema\SchemaManager;
use Register\Module\Search\SearchIndexRebuilder;
use S2\Cms\Admin\AdminExtension;
use S2\Cms\CmsExtension;
use S2\Cms\Framework\Application;
use S2\Cms\Helper\StringHelper;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\HttpClient\HttpClientException;
use S2\Cms\Install\InstallExtension;
use S2\Cms\Logger\Logger;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;

define('S2_VERSION', '2.0dev');
define('MIN_PHP_VERSION', '8.3.0');

define('S2_ROOT', '../');
define('S2_DEBUG', 1);
define('S2_SHOW_QUERIES', 1);

// We need some stuff
require S2_ROOT . '_vendor/autoload.php';
ContentSecurityPolicy::send();

/**
 * Display styled error message and terminate script execution
 *
 * @param string $message Error message to display (can contain HTML)
 * @param string $title Page title and heading (default: 'Error')
 * @SuppressWarnings("PHPMD.ExitExpression")
 */
function error(string $message, string $title = 'An error was encountered'): never
{
    if (!headers_sent()) {
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        header("$protocol 503", true, 503);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Error-Message: ' . rawurlencode(strip_tags($message)));
    }

    // Clean all output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Security: escape output unless message contains HTML tags
    $safeMessage = strip_tags($message) === $message
        ? htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        : $message;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="Generator" content="Register">
        <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> - Register</title>
        <style>
            :root {
                --error-color: #d32f2f;
                --text-color: #333;
                --border-color: rgba(0, 0, 0, 0.1);
            }

            body {
                padding: 2rem;
                font-family: system-ui, -apple-system, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                line-height: 1.6;
                color: var(--text-color);
                background-color: #fefefe;
                max-width: 800px;
                margin: 3rem auto;
            }

            .error-container {
                padding: 2rem;
                border-left: 4px solid var(--error-color);
                background: white;
                box-shadow: 0 1px 8px -2px rgba(0, 0, 0, 0.13);
                border-radius: 0 4px 4px 0;
            }

            h1 {
                margin: 0 0 1rem;
                color: var(--error-color);
                font-size: 1.8rem;
            }

            .error-message {
                margin: 1rem 0;
                white-space: pre-wrap;
            }

            pre, code {
                font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
                font-size: 0.9em;
            }
        </style>
    </head>
    <body>
    <div class="error-container">
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="error-message"><?php echo $safeMessage ?></div>
    </div>
    </body>
    </html>
    <?php

    exit;
}

if (file_exists(S2_ROOT . s2_get_config_filename())) {
    error(sprintf(
        'The file \'%s\' already exists which would mean that Register is already installed. You should go <a href="%s">here</a> instead.',
        s2_get_config_filename(),
        S2_ROOT
    ));
}

// Make sure we are running at least MIN_PHP_VERSION
if (!function_exists('version_compare') || version_compare(PHP_VERSION, MIN_PHP_VERSION, '<')) {
    error('You are running PHP version ' . PHP_VERSION . '. Register requires at least PHP ' . MIN_PHP_VERSION . ' to run properly. You must upgrade your PHP installation before you can continue.');
}

// Disable error reporting for uninitialized variables
error_reporting(defined('S2_DEBUG') ? E_ALL : E_ALL ^ E_NOTICE);

// Turn off PHP time limit
s2_call_without_warnings(static fn(): bool => set_time_limit(0));

require __DIR__ . '/../_include/setup.php';

if (defined('S2_DEBUG')) {
    $errorHandler = Debug::enable();
} else {
    $errorHandler = ErrorHandler::register();
}
HtmlErrorRenderer::setTemplate(__DIR__ . '/../_include/views/error.php');
$errorHandler->setDefaultLogger(new Logger(S2_ROOT . '_cache/install.log', 'install', LogLevel::DEBUG));

/** @param array<int|string, mixed> $config */
function render_install_config_array(array $config, int $indentLevel = 0): string
{
    $indent      = str_repeat('    ', $indentLevel);
    $nextIndent  = $indent . '    ';
    $resultLines = ['['];

    foreach ($config as $key => $value) {
        $formattedKey = is_int($key) ? $key : "'" . str_replace("'", "\\'", $key) . "'";

        if (is_array($value)) {
            $renderedValue = render_install_config_array($value, $indentLevel + 1);
            $resultLines[] = $nextIndent . $formattedKey . ' => ' . $renderedValue . ',';
        } else {
            $resultLines[] = $nextIndent . $formattedKey . ' => ' . var_export($value, true) . ',';
        }
    }

    $resultLines[] = $indent . ']';

    return implode("\n", $resultLines);
}

function has_register_generator(?string $content): bool
{
    if ($content === null) {
        return false;
    }

    return str_contains($content, '<meta name="Generator" content="Register">') ||
        str_contains($content, '<meta name="Generator" content="S2">');
}

function normalize_install_base_url(string $baseUrl): ?string
{
    $baseUrl = rtrim(trim($baseUrl), '/');
    if ($baseUrl === '' || \strlen($baseUrl) > 2048) {
        return null;
    }

    if (preg_match('/[\x00-\x20\x7f]/', $baseUrl) === 1 || str_contains($baseUrl, '\\')) {
        return null;
    }

    $parsedBaseUrl = parse_url($baseUrl);
    if (!\is_array($parsedBaseUrl) || !isset($parsedBaseUrl['scheme'], $parsedBaseUrl['host'])) {
        return null;
    }

    $scheme = strtolower($parsedBaseUrl['scheme']);
    if (!\in_array($scheme, ['http', 'https'], true)
        || $parsedBaseUrl['host'] === ''
        || isset($parsedBaseUrl['user'])
        || isset($parsedBaseUrl['pass'])
        || isset($parsedBaseUrl['query'])
        || isset($parsedBaseUrl['fragment'])
    ) {
        return null;
    }

    return $scheme . substr($baseUrl, \strlen($parsedBaseUrl['scheme']));
}

/**
 * URL-prefix probing is only needed for legacy routing modes. Restrict it to the local server so
 * the public installer cannot be used as a blind HTTP proxy into an arbitrary network.
 */
function can_probe_install_base_url(string $baseUrl): bool
{
    $host = parse_url($baseUrl, PHP_URL_HOST);
    if (!\is_string($host) || $host === '') {
        return false;
    }

    $serverAddress = $_SERVER['SERVER_ADDR'] ?? null;
    if (!\is_string($serverAddress) || filter_var($serverAddress, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
    $targetPort = parse_url($baseUrl, PHP_URL_PORT);
    $targetPort ??= $scheme === 'https' ? 443 : 80;
    $serverPort = $_SERVER['SERVER_PORT'] ?? null;
    if (!\is_int($targetPort) || !\is_string($serverPort) || !ctype_digit($serverPort) || $targetPort !== (int)$serverPort) {
        return false;
    }

    $host = trim($host, '[]');
    if (strtolower($host) === 'localhost') {
        return $serverAddress === '::1' || str_starts_with($serverAddress, '127.');
    }

    if (filter_var($host, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $packedHost = inet_pton($host);
    $packedServerAddress = inet_pton($serverAddress);

    return $packedHost !== false && $packedServerAddress !== false && hash_equals($packedServerAddress, $packedHost);
}

function generate_config_file(
    HttpClient $httpClient,
    string $dbType,
    string $dbHost,
    string $dbName,
    string $dbUsername,
    string $dbPassword,
    string $dbPrefix,
    string $baseUrl,
    string $cookieName,
    ?string $antispamSecret = null,
    bool $probeBaseUrl = false,
): string
{
    $baseUrl = normalize_install_base_url($baseUrl)
        ?? throw new \InvalidArgumentException('Invalid installation base URL.');

    if ($antispamSecret === null || \strlen($antispamSecret) < 32) {
        $antispamSecret = bin2hex(random_bytes(32));
    }

    $urlPrefix = '';
    if ($probeBaseUrl) {
        foreach (['', '/?', '/index.php', '/index.php?'] as $prefix) {
            $urlPrefix = $prefix;
            try {
                $response = $httpClient->request(
                    'GET',
                    $baseUrl . $urlPrefix . '/this/URL/_DoEs_/_NoT_/_eXiSt',
                    options: [HttpClient::FOLLOW_REDIRECTS => false],
                );
                if (has_register_generator($response->content)) {
                    break;
                }
            } catch (HttpClientException) {
                continue;
            }
        }
    }

    $path = (string)(parse_url($baseUrl, PHP_URL_PATH) ?? '');
    $useHttps = str_starts_with($baseUrl, 'https://');

    $config = [
        'database' => [
            'type'      => $dbType,
            'host'      => $dbHost,
            'name'      => $dbName,
            'user'      => $dbUsername,
            'password'  => $dbPassword,
            'prefix'    => $dbPrefix,
            'p_connect' => false,
        ],
        'http'     => [
            'base_url'   => $baseUrl,
            'base_path'  => $path,
            'url_prefix' => $urlPrefix,
            'trusted_proxies' => [],
        ],
        'options'  => [
            'force_admin_https' => $useHttps,
            'canonical_url'     => null,
            'debug'             => 0,
            'debug_view'        => 0,
            'show_queries'      => 0,
        ],
        'cookies'  => [
            'name' => $cookieName,
        ],
        'security' => [
            'antispam_secret' => $antispamSecret,
        ],
        'backups' => [
            'enabled'   => true,
            'directory' => null,
            'retention' => 7,
        ],
    ];

    return "<?php\n\nreturn " . render_install_config_array($config) . ";\n";
}

/** @param list<string> $languages */
function get_preferred_lang(array $languages): string
{
    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
    if (!is_string($acceptLanguage)) {
        return 'English';
    }

    $langs = [];

    // Break up string into pieces (languages and q factors)
    preg_match_all('#([a-z]{1,8}(?:-[a-z]{1,8})?)?\s*(?:;\s*q\s*=\s*(1|0\.[0-9]+))?#i', $acceptLanguage, $languageMatches);

    foreach ($languageMatches[1] as $index => $languageCode) {
        if ($languageCode === '') {
            continue;
        }

        $quality = $languageMatches[2][$index];
        $langs[$languageCode] = $quality === '' ? 1.0 : (float)$quality;
    }
    arsort($langs, SORT_NUMERIC);

    foreach (array_keys($langs) as $languageCode) {
        [$shortLanguageCode] = explode('-', $languageCode);
        foreach ($languages as $availableLanguage) {
            if (strtolower(substr($availableLanguage, 0, 2)) === strtolower($shortLanguageCode)) {
                return $availableLanguage;
            }
        }
    }

    return 'English';
}

/** @return array<string, mixed> */
function installApplicationParameters(
    ?string $dbType = null,
    ?string $dbHost = null,
    ?string $dbName = null,
    ?string $dbUsername = null,
    ?string $dbPassword = null,
    ?string $dbPrefix = null,
    string $baseUrl = '',
): array {
    $basePath = preg_replace('#^[^:/]+://[^/]*#', '', $baseUrl) ?? '';

    return [
        'root_dir'      => S2_ROOT,
        'cache_dir'     => s2_get_default_cache_dir(),
        'disable_cache' => false,
        'log_dir'       => s2_get_default_cache_dir(),
        'base_url'      => $baseUrl,
        'base_path'     => $basePath,
        'trusted_proxies' => [],
        'url_prefix'    => '',
        'debug'         => defined('S2_DEBUG'),
        'debug_view'    => defined('S2_DEBUG_VIEW'),
        'redirect_map'  => [],
        'db_type'       => $dbType,
        'db_host'       => $dbHost,
        'db_name'       => $dbName,
        'db_username'   => $dbUsername,
        'db_password'   => $dbPassword,
        'db_prefix'     => $dbPrefix,
        'p_connect'     => false,
    ];
}

/** @return array{Application, DbLayer} */
function createInstallationApplication(
    string $dbType,
    string $dbHost,
    string $dbName,
    string $dbUsername,
    string $dbPassword,
    string $dbPrefix,
    string $baseUrl,
): array {
    $application = new Application();
    $baseModuleRegistry = new BaseModuleRegistry();
    (new RegisterKernel($baseModuleRegistry))->registerBaseModules($application, false);
    $application->boot(installApplicationParameters(
        $dbType,
        $dbHost,
        $dbName,
        $dbUsername,
        $dbPassword,
        $dbPrefix,
        $baseUrl,
    ));

    $dbLayer = $application->container->get(DbLayer::class);
    $dbLayer->query('SELECT 1;');

    return [$application, $dbLayer];
}

$emptyApp = new Application();
$emptyApp->addExtension(new CmsExtension());
$emptyApp->addExtension(new AdminExtension());
$emptyApp->addExtension(new InstallExtension());
$emptyApp->boot(installApplicationParameters());


$resourceProvider = $emptyApp->container->get(\S2\Cms\Admin\ResourceProvider::class);
$languages        = $resourceProvider->readLanguages();

function installPostString(string $name): string
{
    $value = $_POST[$name] ?? '';
    return is_string($value) ? $value : '';
}

function installServerString(string $name, string $default): string
{
    $value = $_SERVER[$name] ?? null;
    return is_string($value) ? $value : $default;
}

$requestedLanguage = $_GET['lang'] ?? null;
if (!is_string($requestedLanguage)) {
    $requestedLanguage = isset($_POST['req_language']) ? installPostString('req_language') : get_preferred_lang($languages);
}

$language = preg_replace('#[\.\\\/]#', '', $requestedLanguage) ?? 'English';
if (!file_exists(S2_ROOT . '_lang/' . $language . '/common.php')) {
    error("The language pack you have chosen doesn't seem to exist or is corrupt. Please recheck and try again.");
}

/** @var \S2\Cms\Config\InstallationConfigProvider $dynamicConfigProvider */
$dynamicConfigProvider = $emptyApp->container->get(\S2\Cms\Config\DynamicConfigProvider::class);
$dynamicConfigProvider->setCallback(static fn(string $paramName): string => match ($paramName) {
    'S2_LANGUAGE' => $language,
    default => throw new LogicException(sprintf('Parameter "%s" is not available during installation.', $paramName))
});

// Load the language files
/** @var \Symfony\Contracts\Translation\TranslatorInterface $translator */
$translator = $emptyApp->container->get('translator');
require S2_ROOT . '_admin/lang/' . $translator->getLocale() . '/install.php';
/** @var array<string, string> $lang_install */

if (isset($_POST['generate_config'])) {
    $baseUrl = normalize_install_base_url(installPostString('base_url'));
    if ($baseUrl === null) {
        error($lang_install['Invalid base url']);
    }

    header(sprintf('Content-Type: text/plain; charset=utf-8; name="%s"', s2_get_config_filename()));
    header(sprintf("Content-disposition: attachment; filename=%s", s2_get_config_filename()));
    header('X-Content-Type-Options: nosniff');

    echo generate_config_file(
        $emptyApp->container->get(HttpClient::class),
        installPostString('db_type'),
        installPostString('db_host'),
        installPostString('db_name'),
        installPostString('db_username'),
        installPostString('db_password'),
        installPostString('db_prefix'),
        $baseUrl,
        installPostString('cookie_name'),
        null,
        can_probe_install_base_url($baseUrl),
    );
    exit;
}

header('X-Powered-By: Register/' . S2_VERSION);
header('Content-Type: text/html; charset=utf-8');

function guessBaseUrl(): string
{
    $host       = installServerString('HTTP_HOST', 'localhost');
    $scriptName = installServerString('SCRIPT_NAME', '/_admin/install.php');

    $result =
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
        . (preg_replace('/:80$/', '', $host) ?? $host)
        . substr(str_replace('\\', '/', dirname($scriptName)), 0, -6);

    return normalize_install_base_url($result) ?? 'http://localhost';
}

/**
 * @param array<string, string> $lang_install
 * @param list<string> $languages
 * @param array<string, string> $values
 * @param array<string, list<string>> $validationErrors
 */
function renderInstallForm(array $lang_install, array $languages, string $currentLanguage, array $values, array $validationErrors): void
{
    // Determine available database extensions
    $supportedDatabases = [
        'mysql'  => [
            'title'     => 'MySQL',
            'available' => class_exists(PDO::class) && in_array('mysql', PDO::getAvailableDrivers(), true),
        ],
        'sqlite' => [
            'title'     => 'SQLite',
            'available' => class_exists(PDO::class) && in_array('sqlite', PDO::getAvailableDrivers(), true),
        ],
        'pgsql'  => [
            'title'     => 'PostgreSQL',
            'available' => class_exists(PDO::class) && in_array('pgsql', PDO::getAvailableDrivers(), true),
        ],
    ];

    if (count(array_filter($supportedDatabases, static fn(array $db): bool => $db['available'])) === 0) {
        error($lang_install['No database support']);
    }

    // Make an educated guess regarding base_url
    $base_url_guess = guessBaseUrl();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <meta name="Generator" content="Register <?php echo S2_VERSION; ?>">
        <title><?php printf($lang_install['Install S2'], S2_VERSION) ?></title>
        <link rel="icon" type="image/svg+xml" href="<?php echo S2_ROOT ?>_styles/register/favicon.svg">
        <link rel="stylesheet" href="<?php echo S2_ROOT ?>_admin/css/style.css">
    </head>
    <body>

    <h1><?php printf($lang_install['Install S2'], S2_VERSION) ?></h1>

    <?php

    if (count($languages) > 1) {

        ?>
        <form method="get" accept-charset="utf-8" action="install.php">
            <h2><?php echo $lang_install['Part 0'] ?></h2>
            <div class="input select">
                <label for="fld0">
                <span><?php echo $lang_install['Installer language'] ?>
                </span><select id="fld0" name="lang">
                        <?php

                        foreach ($languages as $lang) {
                            echo '<option value="' . $lang . '"' . ($currentLanguage === $lang ? ' selected="selected"' : '') . '>' . $lang . '</option>' . "\n";
                        }

                        ?>
                    </select>
                    <br/>
                    <small><?php echo $lang_install['Choose language help'] ?></small>
                </label>
            </div>
            <div class="button-wrapper"><input type="submit" name="changelang"
                                               value="<?php echo $lang_install['Choose language'] ?>"/></div>
            <input type="hidden" name="form_sent" value="1"/>
        </form>
        <?php

    }

    ?>
    <h2><?php echo $lang_install['Part1'] ?></h2>
    <div class="info-box">
        <p><?php echo $lang_install['Part1 intro'] ?></p>
    </div>
    <?php if (isset($validationErrors['db_error'])): ?>
        <div class="error-box">
            <p><?php echo s2_htmlencode(sprintf($lang_install['Database error'], $validationErrors['db_error'][0])); ?></p>
        </div>
    <?php endif; ?>
    <?php if (isset($validationErrors['db_is_used'])): ?>
        <div class="error-box">
            <p><?php echo s2_htmlencode($validationErrors['db_is_used'][0]); ?></p>
            <p><?php echo $lang_install['S2 already installed 2'] ?></p>
            <p><?php echo $lang_install['S2 already installed 3'] ?></p>
            <form method="post" accept-charset="utf-8" action="install.php">
                <input type="hidden" name="generate_config" value="1">
                <input type="hidden" name="db_type" value="<?php echo s2_htmlencode($values['req_db_type']); ?>">
                <input type="hidden" name="db_host" value="<?php echo s2_htmlencode($values['req_db_host']); ?>">
                <input type="hidden" name="db_name" value="<?php echo s2_htmlencode($values['req_db_name']); ?>">
                <input type="hidden" name="db_username" value="<?php echo s2_htmlencode($values['db_username']); ?>">
                <input type="hidden" name="db_password" value="<?php echo s2_htmlencode($values['db_password']); ?>">
                <input type="hidden" name="db_prefix" value="<?php echo s2_htmlencode($values['db_prefix']); ?>">
                <input type="hidden" name="base_url" value="<?php echo s2_htmlencode($values['req_base_url']); ?>">
                <input type="hidden" name="cookie_name" value="<?php echo s2_htmlencode('s2_cookie_' . mt_rand()); ?>">
                <div class="button-wrapper"><input type="submit" value="<?php echo $lang_install['Download config'] ?>">
                </div>
            </form>
        </div>
    <?php endif; ?>
    <form name="install_form" method="post" accept-charset="utf-8" action="install.php">
        <input type="hidden" name="form_sent" value="1"/>
        <div class="input radio required">
            <label for="fld1">
                <span><?php echo $lang_install['Database type'] ?><em>*</em>
                </span><?php

                $selected = false;
                foreach ($supportedDatabases as $dbType => $dbInfo) {
                    $enabled   = $dbInfo['available'];
                    $isChecked = (isset($values['req_db_type']) ? $values['req_db_type'] === $dbType : $enabled && !$selected) ? 'checked' : '';
                    echo '<label ' . ($enabled ? '' : 'class="disabled"') . '><input type="radio" name="req_db_type" ' . $isChecked . ' value="' . $dbType . '" ' . ($enabled ? '' : 'disabled="disabled"') . '><span>' . $dbInfo['title'] . ($enabled ? '' : ' ' . $lang_install['Database type N/A']) . '</span></label>' . "<br>\n";
                    if ($enabled) {
                        $selected = true;
                    }
                }

                ?>
            </label>
        </div>
        <script src="js/install.js" defer></script>
        <div class="input text required">
            <label for="fld2">
                <span><?php echo $lang_install['Database server'] ?><em>*</em>
                </span><input id="fld2" type="text" name="req_db_host"
                              value="<?php echo s2_htmlencode($values['req_db_host'] ?? 'localhost'); ?>" size="50"
                              maxlength="100"/>
                <?php foreach ($validationErrors['req_db_host'] ?? [] as $error) {
                    echo '<small class="error">' . s2_htmlencode($error) . '</small>';
                } ?>
                <small><?php echo $lang_install['Database server help'] ?></small>
            </label>
        </div>
        <div class="input text required">
            <label for="fld3">
                <span><?php echo $lang_install['Database name'] ?><em>*</em>
                </span><input id="fld3" type="text" name="req_db_name"
                              value="<?php echo s2_htmlencode($values['req_db_name'] ?? ''); ?>" size="35"
                              maxlength="50"/>
                <?php foreach ($validationErrors['req_db_name'] ?? [] as $error) {
                    echo '<small class="error">' . s2_htmlencode($error) . '</small>';
                } ?>
                <small><?php echo $lang_install['Database name help'] ?></small>
            </label>
        </div>
        <div class="input text">
            <label for="fld4">
                <span><?php echo $lang_install['Database username'] ?>
                </span><input id="fld4" type="text" name="db_username"
                              value="<?php echo s2_htmlencode($values['db_username'] ?? ''); ?>" size="35"
                              maxlength="50"/>
                <?php foreach ($validationErrors['db_username'] ?? [] as $error) {
                    echo '<small class="error">' . s2_htmlencode($error) . '</small>';
                } ?>
                <small><?php echo $lang_install['Database username help'] ?></small>
            </label>
        </div>
        <div class="input text">
            <label for="fld5">
                <span><?php echo $lang_install['Database password'] ?>
                </span><input id="fld5" type="password" name="db_password"
                              value="<?php echo s2_htmlencode($values['db_password'] ?? ''); ?>" size="35"/>
                <?php foreach ($validationErrors['db_password'] ?? [] as $error) {
                    echo '<small class="error">' . s2_htmlencode($error) . '</small>';
                } ?>
                <small><?php echo $lang_install['Database password help'] ?></small>
            </label>
        </div>
        <div class="input text">
            <label for="fld6">
                <span><?php echo $lang_install['Table prefix'] ?>
                </span><input id="fld6" type="text" name="db_prefix" size="20" maxlength="30"
                              value="<?php echo s2_htmlencode($values['db_prefix'] ?? ''); ?>">
                <?php foreach ($validationErrors['db_prefix'] ?? [] as $error) {
                    echo '<small class="error">' . s2_htmlencode($error) . '</small>';
                } ?>
                <small><?php echo $lang_install['Table prefix help'] ?></small>
            </label>
        </div>

        <h2><?php echo $lang_install['Part2'] ?></h2>
        <div class="info-box">
            <p><?php echo $lang_install['Part2 intro'] ?></p>
        </div>
        <div class="input text required">
            <label for="fld7">
                <span><?php echo $lang_install['Admin username'] ?><em>*</em>
                </span><input id="fld7" type="text" name="req_username" size="35" maxlength="40"
                              value="<?php echo s2_htmlencode($values['req_username'] ?? 'admin'); ?>"/>
                <?php foreach ($validationErrors['req_username'] ?? [] as $error) {
                    echo '<small class="error">' . s2_htmlencode($error) . '</small>';
                } ?>
            </label>
        </div>
        <div class="input text required">
            <label for="fld8">
                <span><?php echo $lang_install['Admin password'] ?>
                </span><input id="fld8" type="password" name="req_password" size="35" maxlength="200"
                              value="<?php echo s2_htmlencode($values['req_password'] ?? ''); ?>"/>
                <?php foreach ($validationErrors['req_password'] ?? [] as $error) {
                    echo '<small class="error">' . s2_htmlencode($error) . '</small>';
                } ?>
            </label>
        </div>
        <div class="input text">
            <label for="fld10">
                <span><?php echo $lang_install['Admin e-mail'] ?>
                </span><input id="fld10" type="text" name="adm_email" size="50" maxlength="80"
                              value="<?php echo s2_htmlencode($values['adm_email'] ?? ''); ?>"/>
                <?php foreach ($validationErrors['adm_email'] ?? [] as $error) {
                    echo '<small class="error">' . s2_htmlencode($error) . '</small>';
                } ?>
                <small><?php echo $lang_install['E-mail address help'] ?></small>
            </label>
        </div>
        <h2><?php echo $lang_install['Part3'] ?></h2>
        <div class="input text required">
            <label for="fld13">
                <span><?php echo $lang_install['Base URL'] ?><em>*</em>
                </span><input id="fld13" type="text" name="req_base_url" maxlength="100" size="50"
                              value="<?php echo s2_htmlencode($values['req_base_url'] ?? $base_url_guess); ?>">
                <?php foreach ($validationErrors['req_base_url'] ?? [] as $error) {
                    echo '<small class="error">' . s2_htmlencode($error) . '</small>';
                } ?>
                <small><?php echo $lang_install['Base URL help'] ?></small>
            </label>
        </div>
        <?php

        if (count($languages) > 1) {

            ?>
            <div class="input select">
                <label for="fld14">
                <span><?php echo $lang_install['Default language'] ?>
                </span><select id="fld14" name="req_language">
                        <?php

                        foreach ($languages as $lang)
                            echo '<option value="' . $lang . '"' . (($values['req_language'] ?? $currentLanguage) === $lang ? ' selected="selected"' : '') . '>' . $lang . '</option>' . "\n";

                        ?>
                    </select>
                    <br/>
                    <?php foreach ($validationErrors['req_language'] ?? [] as $error) {
                        echo '<small class="error">' . s2_htmlencode($error) . '</small>';
                    } ?>
                    <small><?php echo $lang_install['Default language help'] ?></small>
                </label>
            </div>
            <?php

        } else {

            ?>
            <input type="hidden" name="req_language" value="<?php echo $languages[0]; ?>"/>
            <?php
        }

        ?>
        <div class="button-wrapper">
            <input type="submit" name="start" class="main-button" value="<?php echo $lang_install['Start install'] ?>"/>
        </div>
    </form>

    </body>
    </html>
    <?php

}


if (!isset($_POST['form_sent'])) {
    renderInstallForm($lang_install, $languages, $language, [], []);
    exit;
}

$db_type      = installPostString('req_db_type');
$db_host      = trim(installPostString('req_db_host'));
$db_name      = trim(installPostString('req_db_name'));
$db_username  = trim(installPostString('db_username'));
$db_password  = trim(installPostString('db_password'));
$db_prefix    = trim(installPostString('db_prefix'));
$username     = trim(installPostString('req_username'));
$password     = trim(installPostString('req_password'));
$email        = strtolower(trim(installPostString('adm_email')));
$default_lang = preg_replace('#[\.\\\/]#', '', trim(installPostString('req_language'))) ?? '';

// Make sure base_url doesn't end with a slash
$requestedBaseUrl = installPostString('req_base_url');
$normalizedBaseUrl = normalize_install_base_url($requestedBaseUrl);
$base_url = $normalizedBaseUrl ?? rtrim(trim($requestedBaseUrl), '/');

// Validate form
$validationErrors = [];
if (!\in_array($db_type, ['mysql', 'sqlite', 'pgsql'], true)) {
    $validationErrors['req_db_type'][] = sprintf($lang_install['No such database type'], $db_type);
}
if (mb_strlen($db_name) === 0) {
    $validationErrors['req_db_name'][] = $lang_install['Missing database name'];
}

// Validate prefix
if (strlen($db_prefix) > 40) {
    $validationErrors['db_prefix'][] = sprintf($lang_install['Too long table prefix'], $db_prefix);
}

if ($db_prefix !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $db_prefix) !== 1) {
    $validationErrors['db_prefix'][] = sprintf($lang_install['Invalid table prefix'], $db_prefix);
}

// Check SQLite prefix collision
if ($db_type === 'sqlite' && strtolower($db_prefix) === 'sqlite_') {
    $validationErrors['db_prefix'][] = $lang_install['SQLite prefix collision'];
}


// Validate username
if (mb_strlen($username) < 2) {
    $validationErrors['req_username'][] = $lang_install['Username too short'];
}
if (mb_strlen($username) > 40) {
    $validationErrors['req_username'][] = $lang_install['Username too long'];
}

// Validate password
if (mb_strlen($password) < 4) {
    $validationErrors['req_password'][] = $lang_install['Password too short'];
}
if (mb_strlen($password) > 100) {
    $validationErrors['req_password'][] = $lang_install['Password too long'];
}

// Validate email
if ($email !== '' && !StringHelper::isValidEmail($email)) {
    $validationErrors['adm_email'][] = $lang_install['Invalid email'];
}

if ($base_url === '') {
    $validationErrors['req_base_url'][] = $lang_install['Missing base url'];
} elseif ($normalizedBaseUrl === null) {
    $validationErrors['req_base_url'][] = $lang_install['Invalid base url'];
}

if (!file_exists(S2_ROOT . '_lang/' . $default_lang . '/common.php')) {
    $validationErrors['req_language'][] = $lang_install['Invalid language'];
}

$submittedValues = [
    'req_db_type'  => $db_type,
    'req_db_host'  => $db_host,
    'req_db_name'  => $db_name,
    'db_username'  => $db_username,
    'db_password'  => $db_password,
    'db_prefix'    => $db_prefix,
    'req_username' => $username,
    'req_password' => $password,
    'adm_email'    => $email,
    'req_language' => $default_lang,
    'req_base_url' => $base_url,
];

if ($validationErrors !== []) {
    renderInstallForm($lang_install, $languages, $language, $submittedValues, $validationErrors);
    exit;
}

try {
    [$app, $s2_db] = createInstallationApplication(
        $db_type,
        $db_host,
        $db_name,
        $db_username,
        $db_password,
        $db_prefix,
        $base_url,
    );
} catch (\Throwable $throwable) {
    $validationErrors['db_error'][] = $throwable->getMessage();
    renderInstallForm($lang_install, $languages, $language, $submittedValues, $validationErrors);
    exit;
}

// Make sure Register isn't already installed.
try {
    $result           = $s2_db->select('count(id)')->from('users')->execute();
    $databaseHasUsers = $result->fetchRow() !== false;
} catch (DbLayerException|PDOException) {
    $databaseHasUsers = false;
}

if ($databaseHasUsers) {
    $validationErrors['db_is_used'][] = sprintf($lang_install['S2 already installed'], $db_prefix, $db_name);
    renderInstallForm($lang_install, $languages, $language, $submittedValues, $validationErrors);
    exit;
}


if ($db_type !== 'mysql') {
    // Skip for MySQL, as it implicitly commits a transaction on DDL queries
    $s2_db->startTransaction();
}

$installer = new \S2\Cms\Model\Installer($s2_db);
$installer->createTables();

$now = time();

// Admin user
$s2_db
    ->insert('users')
    ->setValue('login', ':login')->setParameter('login', $username)
    ->setValue('password', ':password')->setParameter('password', password_hash($password, PASSWORD_DEFAULT))
    ->setValue('email', ':email')->setParameter('email', $email)
    ->setValue('view', '1')
    ->setValue('view_hidden', '1')
    ->setValue('hide_comments', '1')
    ->setValue('edit_comments', '1')
    ->setValue('create_articles', '1')
    ->setValue('edit_site', '1')
    ->setValue('edit_users', '1')
    ->execute()
;
$admin_uid = $s2_db->insertId();

$antispamSecret = bin2hex(random_bytes(32));
$installer->insertConfigData(
    $lang_install['Site name'],
    $email,
    $default_lang,
);

$app->container->get(SchemaManager::class)->ensureCurrent();

// Insert some other default data
$rootPageId = $installer->insertMainPage($lang_install['Main Page'], $now);
$s2_db->insert(ContentSchema::TABLE_NAME)
    ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
    ->setValue('parent_id', ':parent_id')->setParameter('parent_id', $rootPageId)
    ->setValue('slug_scope', "'root'")
    ->setValue('title', ':title')->setParameter('title', $lang_install['Section example'])
    ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
    ->setValue('published_at', ':published_at')->setParameter('published_at', $now)
    ->setValue('updated_at', ':updated_at')->setParameter('updated_at', $now)
    ->setValue('published', '1')
    ->setValue('template', "'site.php'")
    ->setValue('slug', "'section1'")
    ->setValue('excerpt', "''")
    ->setValue('body', "''")
    ->execute()
;
$sectionId = (int)$s2_db->insertId();
$s2_db
    ->insert(ContentSchema::TABLE_NAME)
    ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
    ->setValue('parent_id', ':parent_id')->setParameter('parent_id', $sectionId)
    ->setValue('slug_scope', ':slug_scope')->setParameter('slug_scope', 'page:' . $sectionId)
    ->setValue('title', ':title')->setParameter('title', $lang_install['Page example'])
    ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
    ->setValue('published_at', ':published_at')->setParameter('published_at', $now)
    ->setValue('updated_at', ':updated_at')->setParameter('updated_at', $now)
    ->setValue('published', '1')
    ->setValue('template', "''")
    ->setValue('slug', "'page1'")
    ->setValue('body', ':body')->setParameter('body', $lang_install['Page text'])
    ->setValue('excerpt', ':excerpt')->setParameter('excerpt', $lang_install['Page text'])
    ->setValue('author_id', ':author_id')->setParameter('author_id', $admin_uid)
    ->execute()
;
(new WelcomePostInstaller($s2_db))->create($lang_install['Welcome title'], $lang_install['Welcome text'], (int)$admin_uid, $now);

$app->container->get(SearchIndexRebuilder::class)->rebuild();

if ($db_type !== 'mysql') {
    $s2_db->endTransaction();
}

$cache = $app->container->get(ExtensionCache::class);
$cache->clear();

$alerts = [];
// Check if the cache directory is writable
if (!is_writable(s2_get_default_cache_dir())) {
    $alerts[] = '<li><span>' . $lang_install['No cache write'] . '</span></li>';
}

// Check if default pictures directory is writable
if (!is_writable(S2_ROOT . '_pictures/')) {
    $alerts[] = '<li><span>' . $lang_install['No pictures write'] . '</span></li>';
}

// Check if we disabled uploading pictures because file_uploads was disabled
$uploads = in_array(strtolower((string)ini_get('file_uploads')), ['on', 'true', '1'], true);
if (!$uploads) {
    $alerts[] = '<li><span>' . $lang_install['File upload alert'] . '</span></li>';
}

// Add some random bytes at the end of the cookie name to prevent collisions
$s2_cookie_name = 's2_cookie_' . mt_rand();

/// Generate the config.php file data
$config = generate_config_file(
    $app->container->get(HttpClient::class),
    $db_type,
    $db_host,
    $db_name,
    $db_username,
    $db_password,
    $db_prefix,
    $base_url,
    $s2_cookie_name,
    $antispamSecret,
    can_probe_install_base_url($base_url),
);

// Attempt to write config.php and serve it up for download if writing fails
$written = false;
if (is_writable(S2_ROOT)) {
    $fh = s2_call_without_warnings(static fn() => fopen(S2_ROOT . s2_get_config_filename(), 'wb'));
    if ($fh !== false) {
        fwrite($fh, $config);
        fclose($fh);

        $written = true;
    }
}

?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="Generator" content="Register <?php echo S2_VERSION; ?>"/>
        <title><?php printf($lang_install['Install S2'], S2_VERSION) ?></title>
        <link rel="icon" type="image/svg+xml" href="<?php echo S2_ROOT ?>_styles/register/favicon.svg">
        <link rel="stylesheet" type="text/css" href="<?php echo S2_ROOT ?>_admin/css/style.css"/>
    </head>
    <body>

    <h1><?php printf($lang_install['Install S2'], S2_VERSION) ?></h1>
    <p><?php printf($lang_install['Success description'], S2_VERSION) ?></p>
    <p><?php echo $lang_install['Success welcome'] ?></p>
    <h2><?php echo $lang_install['Final instructions'] ?></h2>
    <?php

    if ($alerts !== []) {

        ?>
        <div class="warning-box">
            <p class="warn"><strong><?php echo $lang_install['Warning'] ?></strong></p>
            <ul>
                <?php echo implode("\n\t\t\t\t", $alerts) . "\n" ?>
            </ul>
        </div>
        <?php

    }

    if (!$written) {

        ?>
        <div class="warning-box">
            <p class="warn"><?php echo $lang_install['No write info 1'] ?></p>
            <p class="warn"><?php printf($lang_install['No write info 2'], '<a href="' . S2_ROOT . '">' . $lang_install['Go to index'] . '</a>') ?></p>
        </div>
        <form method="post" accept-charset="utf-8" action="install.php">
            <input type="hidden" name="generate_config" value="1"/>
            <input type="hidden" name="db_type" value="<?php echo s2_htmlencode($db_type); ?>"/>
            <input type="hidden" name="db_host" value="<?php echo s2_htmlencode($db_host); ?>"/>
            <input type="hidden" name="db_name" value="<?php echo s2_htmlencode($db_name); ?>"/>
            <input type="hidden" name="db_username" value="<?php echo s2_htmlencode($db_username); ?>"/>
            <input type="hidden" name="db_password" value="<?php echo s2_htmlencode($db_password); ?>"/>
            <input type="hidden" name="db_prefix" value="<?php echo s2_htmlencode($db_prefix); ?>"/>
            <input type="hidden" name="base_url" value="<?php echo s2_htmlencode($base_url); ?>"/>
            <input type="hidden" name="cookie_name" value="<?php echo s2_htmlencode($s2_cookie_name); ?>"/>
            <input type="hidden" name="antispam_secret" value="<?php echo s2_htmlencode($antispamSecret); ?>"/>
            <div class="button-wrapper"><input type="submit" value="<?php echo $lang_install['Download config'] ?>"/>
            </div>
        </form>
        <?php

    } else {

        ?>
        <div class="success-box">
            <p class="warn"><?php printf($lang_install['Write info'], '<a href="' . S2_ROOT . '">' . $lang_install['Go to index'] . '</a>') ?></p>
        </div>
        <?php

    }

    ?>

    </body>
    </html>

<?php
