<?php
/**
 * Loads static configuration for the application, providing a normalized array
 * that can be consumed by the bootstrap and container.
 *
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Config;

final class StaticConfigLoader
{
    public const string DEFAULT_IMAGE_DIR          = '_pictures';

    public const string DEFAULT_ALLOWED_EXTENSIONS = 'gif bmp jpg jpeg png ico svg mp3 wav ogg flac mp4 avi flv mpg mpeg mkv zip 7z rar doc docx ppt pptx odt odp ods xlsx xls pdf txt rtf csv';

    public const string DEFAULT_COOKIE_NAME        = 's2_cookie_6094033457';

    /**
     * @return array<mixed>
     */
    public function load(string $filename): array
    {
        if (!\file_exists($filename)) {
            $config = $this->createDefaultConfig();
            $this->overrideWithGlobalConstants($config);
            $this->applyCompatibilityConstants($config, false);
            return $config;
        }

        [$config, $legacyConfig] = $this->includeConfig($filename);

        if (\is_array($config)) {
            $normalized = $this->normalizeArrayConfig($config);
            $this->overrideWithGlobalConstants($normalized);
            $this->applyCompatibilityConstants($normalized, false);
            return $normalized;
        }

        $normalized = $this->normalizeArrayConfig($legacyConfig);
        $this->overrideWithGlobalConstants($normalized);
        $this->applyCompatibilityConstants($normalized, true);
        return $normalized;
    }

    /**
     * @return array<mixed>
     */
    private function createDefaultConfig(): array
    {
        return $this->normalizeArrayConfig([]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed[]>
     */
    private function normalizeArrayConfig(array $config): array
    {
        $database  = $config['database'] ?? [];
        $http      = $config['http'] ?? [];
        $options   = $config['options'] ?? [];
        $files     = $config['files'] ?? [];
        $cookies   = $config['cookies'] ?? [];
        $security  = $config['security'] ?? [];
        $redirects = $config['redirects'] ?? [];

        $normalizeDir = static function (?string $dir): ?string {
            if ($dir === null || $dir === '') {
                return $dir === '' ? '' : null;
            }

            return rtrim($dir, '/') . '/';
        };

        return [
            'database' => [
                'type'      => $this->nullableString($database['type'] ?? null),
                'host'      => $this->nullableString($database['host'] ?? null),
                'name'      => $this->nullableString($database['name'] ?? null),
                'user'      => $this->nullableString($database['user'] ?? null),
                'password'  => $this->nullableString($database['password'] ?? null),
                'prefix'    => $this->nullableString($database['prefix'] ?? null),
                'p_connect' => $this->toBool($database['p_connect'] ?? false),
            ],
            'http' => [
                'base_url'   => $this->nullableString($http['base_url'] ?? null),
                'base_path'  => $this->nullableString($http['base_path'] ?? null, ''),
                'url_prefix' => $this->nullableString($http['url_prefix'] ?? null, ''),
            ],
            'options' => [
                'force_admin_https' => $this->toBool($options['force_admin_https'] ?? false),
                'canonical_url'     => $this->nullableString($options['canonical_url'] ?? null),
                'disable_cache'     => $this->toBool($options['disable_cache'] ?? false),
                'debug'             => $this->toBool($options['debug'] ?? false),
                'debug_view'        => $this->toBool($options['debug_view'] ?? false),
                'show_queries'      => $this->toBool($options['show_queries'] ?? false),
            ],
            'files' => [
                'cache_dir'          => $normalizeDir($this->nullableString($files['cache_dir'] ?? null)),
                'image_dir'          => $this->nullableString($files['image_dir'] ?? null, self::DEFAULT_IMAGE_DIR),
                'allowed_extensions' => $this->nullableString($files['allowed_extensions'] ?? null, self::DEFAULT_ALLOWED_EXTENSIONS),
                'log_dir'            => $normalizeDir($this->nullableString($files['log_dir'] ?? null)),
            ],
            'cookies' => [
                'name' => $this->nullableString($cookies['name'] ?? null, self::DEFAULT_COOKIE_NAME),
            ],
            'security' => [
                'antispam_secret' => $this->nullableString($security['antispam_secret'] ?? null),
            ],
            'redirects' => \is_array($redirects) ? $redirects : [],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function overrideWithGlobalConstants(array &$config): void
    {
        if (\defined('S2_CACHE_DIR')) {
            $config['files']['cache_dir'] = rtrim((string)S2_CACHE_DIR, '/') . '/';
        }

        if (\defined('S2_LOG_DIR')) {
            $config['files']['log_dir'] = rtrim((string)S2_LOG_DIR, '/') . '/';
        }

        if (\defined('S2_PATH')) {
            $config['http']['base_path'] = (string)S2_PATH;
        }

        if (\defined('S2_BASE_URL')) {
            $config['http']['base_url'] = (string)S2_BASE_URL;
        }

        if (\defined('S2_URL_PREFIX')) {
            $config['http']['url_prefix'] = (string)S2_URL_PREFIX;
        }

        if (\defined('S2_CANONICAL_URL')) {
            $config['options']['canonical_url'] = (string)S2_CANONICAL_URL;
        }

        if (\defined('S2_FORCE_ADMIN_HTTPS')) {
            $config['options']['force_admin_https'] = true;
        }

        if (\defined('S2_DISABLE_CACHE')) {
            $config['options']['disable_cache'] = true;
        }

        if (\defined('S2_DEBUG')) {
            $config['options']['debug'] = true;
        }

        if (\defined('S2_DEBUG_VIEW')) {
            $config['options']['debug_view'] = true;
        }

        if (\defined('S2_SHOW_QUERIES')) {
            $config['options']['show_queries'] = true;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyCompatibilityConstants(array $config, bool $legacyFormatUsed): void
    {
        if ($legacyFormatUsed) {
            return;
        }

        if (isset($config['files']['cache_dir']) && !\defined('S2_CACHE_DIR')) {
            \define('S2_CACHE_DIR', $config['files']['cache_dir']);
        }

        if (isset($config['files']['log_dir']) && !\defined('S2_LOG_DIR')) {
            \define('S2_LOG_DIR', $config['files']['log_dir']);
        }
    }

    /**
     * Includes the config file once and returns both the raw include result
     * and the legacy-style data inferred from globals/constants.
     *
     * @return array{0:mixed,1:array<mixed>}
     */
    private function includeConfig(string $filename): array
    {
        return (static function (string $filename): array {
            $db_type     = null;
            $db_host     = null;
            $db_name     = null;
            $db_username = null;
            $db_password = null;
            $db_prefix   = null;
            $p_connect   = false;

            $config = include $filename;
            $legacyVariables = get_defined_vars();
            $legacyCookieName = $legacyVariables['s2_cookie_name'] ?? null;
            $legacyRedirects  = $legacyVariables['s2_redirect'] ?? [];

            return [
                $config,
                [
                    'database' => [
                        'type'      => $db_type,
                        'host'      => $db_host,
                        'name'      => $db_name,
                        'user'      => $db_username,
                        'password'  => $db_password,
                        'prefix'    => $db_prefix,
                        'p_connect' => $p_connect,
                    ],
                    'http' => [
                        'base_url'   => \defined('S2_BASE_URL') ? (string)S2_BASE_URL : null,
                        'base_path'  => \defined('S2_PATH') ? (string)S2_PATH : '',
                        'url_prefix' => \defined('S2_URL_PREFIX') ? (string)S2_URL_PREFIX : '',
                    ],
                    'options' => [
                        'force_admin_https' => \defined('S2_FORCE_ADMIN_HTTPS'),
                        'canonical_url'     => \defined('S2_CANONICAL_URL') ? (string)S2_CANONICAL_URL : null,
                        'disable_cache'     => \defined('S2_DISABLE_CACHE'),
                        'debug'             => \defined('S2_DEBUG'),
                        'debug_view'        => \defined('S2_DEBUG_VIEW'),
                        'show_queries'      => \defined('S2_SHOW_QUERIES'),
                    ],
                    'files' => [
                        'cache_dir'          => \defined('S2_CACHE_DIR') ? (string)S2_CACHE_DIR : null,
                        'image_dir'          => \defined('S2_IMG_DIR') ? (string)S2_IMG_DIR : self::DEFAULT_IMAGE_DIR,
                        'allowed_extensions' => \defined('S2_ALLOWED_EXTENSIONS') ? (string)S2_ALLOWED_EXTENSIONS : self::DEFAULT_ALLOWED_EXTENSIONS,
                        'log_dir'            => \defined('S2_LOG_DIR') ? (string)S2_LOG_DIR : null,
                    ],
                    'cookies' => [
                        'name' => \is_string($legacyCookieName) && $legacyCookieName !== '' ? $legacyCookieName : self::DEFAULT_COOKIE_NAME,
                    ],
                    'security' => [
                        'antispam_secret' => null,
                    ],
                    'redirects' => \is_array($legacyRedirects) ? $legacyRedirects : [],
                ],
            ];
        })($filename);
    }

    private function nullableString(mixed $value, ?string $default = null): ?string
    {
        if ($value === null) {
            return $default;
        }

        if (\is_string($value)) {
            return $value;
        }

        if (\is_numeric($value)) {
            return (string)$value;
        }

        return $default;
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
