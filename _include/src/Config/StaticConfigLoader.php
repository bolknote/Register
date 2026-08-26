<?php
/**
 * Loads static configuration for the application, providing a normalized array
 * that can be consumed by the bootstrap and container.
 *
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Config;

final class StaticConfigLoader
{
    public const string DEFAULT_IMAGE_DIR          = '_pictures';

    public const string DEFAULT_ALLOWED_EXTENSIONS = 'gif bmp jpg jpeg png webp avif ico mp3 wav ogg flac mp4 avi mov webm flv mpg mpeg mkv zip 7z rar doc docx ppt pptx odt odp ods xlsx xls pdf txt rtf csv';

    public const string DEFAULT_COOKIE_NAME        = 'register_cookie_6094033457';

    public const int DEFAULT_UPLOAD_QUOTA_BYTES    = 1024 * 1024 * 1024;

    public const int MIN_UPLOAD_QUOTA_BYTES        = 200 * 1024 * 1024;

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
        $backups   = $config['backups'] ?? [];
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
                'trusted_proxies' => $this->stringList($http['trusted_proxies'] ?? []),
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
                'image_url'          => $this->nullableString($files['image_url'] ?? null),
                'content_image_directory' => $this->normalizeMediaSubdirectory($files['content_image_directory'] ?? ''),
                'allowed_extensions' => $this->nullableString($files['allowed_extensions'] ?? null, self::DEFAULT_ALLOWED_EXTENSIONS),
                'upload_quota_bytes' => $this->boundedInt(
                    $files['upload_quota_bytes'] ?? self::DEFAULT_UPLOAD_QUOTA_BYTES,
                    self::DEFAULT_UPLOAD_QUOTA_BYTES,
                    self::MIN_UPLOAD_QUOTA_BYTES,
                    PHP_INT_MAX,
                ),
                'log_dir'            => $normalizeDir($this->nullableString($files['log_dir'] ?? null)),
            ],
            'cookies' => [
                'name' => $this->nullableString($cookies['name'] ?? null, self::DEFAULT_COOKIE_NAME),
            ],
            'security' => [
                'antispam_secret' => $this->nullableString($security['antispam_secret'] ?? null),
                'secret_file'     => $this->nullableString($security['secret_file'] ?? null),
            ],
            'backups' => [
                'enabled'               => $this->toBool($backups['enabled'] ?? true),
                'directory'             => $normalizeDir($this->nullableString($backups['directory'] ?? null)),
                'retention'             => $this->boundedInt($backups['retention'] ?? 7, 7, 1, 365),
                'encryption_key'        => $this->nullableString($backups['encryption_key'] ?? null),
                'recipient_public_key'  => $this->nullableString($backups['recipient_public_key'] ?? null),
                'recipient_private_key' => $this->nullableString($backups['recipient_private_key'] ?? null),
            ],
            'redirects' => \is_array($redirects) ? $redirects : [],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function overrideWithGlobalConstants(array &$config): void
    {
        if (\defined('REGISTER_CACHE_DIR')) {
            $config['files']['cache_dir'] = rtrim((string)REGISTER_CACHE_DIR, '/') . '/';
        }

        if (\defined('REGISTER_LOG_DIR')) {
            $config['files']['log_dir'] = rtrim((string)REGISTER_LOG_DIR, '/') . '/';
        }

        if (\defined('REGISTER_PATH')) {
            $config['http']['base_path'] = (string)REGISTER_PATH;
        }

        if (\defined('REGISTER_BASE_URL')) {
            $config['http']['base_url'] = (string)REGISTER_BASE_URL;
        }

        if (\defined('REGISTER_URL_PREFIX')) {
            $config['http']['url_prefix'] = (string)REGISTER_URL_PREFIX;
        }

        if (\defined('REGISTER_CANONICAL_URL')) {
            $config['options']['canonical_url'] = (string)REGISTER_CANONICAL_URL;
        }

        if (\defined('REGISTER_FORCE_ADMIN_HTTPS')) {
            $config['options']['force_admin_https'] = true;
        }

        if (\defined('REGISTER_DISABLE_CACHE')) {
            $config['options']['disable_cache'] = true;
        }

        if (\defined('REGISTER_DEBUG')) {
            $config['options']['debug'] = true;
        }

        if (\defined('REGISTER_DEBUG_VIEW')) {
            $config['options']['debug_view'] = true;
        }

        if (\defined('REGISTER_SHOW_QUERIES')) {
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

        if (isset($config['files']['cache_dir']) && !\defined('REGISTER_CACHE_DIR')) {
            \define('REGISTER_CACHE_DIR', $config['files']['cache_dir']);
        }

        if (isset($config['files']['log_dir']) && !\defined('REGISTER_LOG_DIR')) {
            \define('REGISTER_LOG_DIR', $config['files']['log_dir']);
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
            $legacyCookieName = $legacyVariables['register_cookie_name'] ?? null;
            $legacyRedirects  = $legacyVariables['register_redirect'] ?? [];

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
                        'base_url'   => \defined('REGISTER_BASE_URL') ? (string)REGISTER_BASE_URL : null,
                        'base_path'  => \defined('REGISTER_PATH') ? (string)REGISTER_PATH : '',
                        'url_prefix' => \defined('REGISTER_URL_PREFIX') ? (string)REGISTER_URL_PREFIX : '',
                        'trusted_proxies' => [],
                    ],
                    'options' => [
                        'force_admin_https' => \defined('REGISTER_FORCE_ADMIN_HTTPS'),
                        'canonical_url'     => \defined('REGISTER_CANONICAL_URL') ? (string)REGISTER_CANONICAL_URL : null,
                        'disable_cache'     => \defined('REGISTER_DISABLE_CACHE'),
                        'debug'             => \defined('REGISTER_DEBUG'),
                        'debug_view'        => \defined('REGISTER_DEBUG_VIEW'),
                        'show_queries'      => \defined('REGISTER_SHOW_QUERIES'),
                    ],
                    'files' => [
                        'cache_dir'          => \defined('REGISTER_CACHE_DIR') ? (string)REGISTER_CACHE_DIR : null,
                        'image_dir'          => \defined('REGISTER_IMG_DIR') ? (string)REGISTER_IMG_DIR : self::DEFAULT_IMAGE_DIR,
                        'image_url'          => null,
                        'content_image_directory' => '',
                        'allowed_extensions' => \defined('REGISTER_ALLOWED_EXTENSIONS') ? (string)REGISTER_ALLOWED_EXTENSIONS : self::DEFAULT_ALLOWED_EXTENSIONS,
                        'upload_quota_bytes' => self::DEFAULT_UPLOAD_QUOTA_BYTES,
                        'log_dir'            => \defined('REGISTER_LOG_DIR') ? (string)REGISTER_LOG_DIR : null,
                    ],
                    'cookies' => [
                        'name' => \is_string($legacyCookieName) && $legacyCookieName !== '' ? $legacyCookieName : self::DEFAULT_COOKIE_NAME,
                    ],
                    'security' => [
                        'antispam_secret' => null,
                        'secret_file'     => null,
                    ],
                    'backups' => [
                        'enabled'               => true,
                        'directory'             => null,
                        'retention'             => 7,
                        'encryption_key'        => null,
                        'recipient_public_key'  => null,
                        'recipient_private_key' => null,
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

    private function normalizeMediaSubdirectory(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException('The content image directory must be a string.');
        }

        $path = '/' . trim(str_replace('\\', '/', $value), '/');
        if (
            str_contains($path, '..')
            || preg_match('~[\x00-\x1f\x7f]~', $path) === 1
            || preg_match('~^/(?:[\p{L}\p{N}._-]+/)*[\p{L}\p{N}._-]+$~uD', $path) !== 1
        ) {
            throw new \InvalidArgumentException('The content image directory is invalid.');
        }

        return $path;
    }

    private function boundedInt(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if (\is_int($value)) {
            $number = $value;
        } elseif (\is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $number = (int)$value;
        } else {
            return $default;
        }

        return $number >= $minimum && $number <= $maximum ? $number : $default;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (\is_string($value)) {
            $items = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
            return $items === false ? [] : $items;
        }

        if (!\is_array($value)) {
            throw new \InvalidArgumentException('Trusted proxies must be configured as an array or a comma-separated string.');
        }

        $result = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw new \InvalidArgumentException('Every trusted proxy must be a string containing an IP or CIDR.');
            }

            $item = trim($item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
