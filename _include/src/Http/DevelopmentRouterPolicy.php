<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Http;

final class DevelopmentRouterPolicy
{
    private const array PHP_ENDPOINTS = [
        '/index.php',
        '/_admin/ajax.php',
        '/_admin/index.php',
        '/_admin/install.php',
        '/_admin/pictman.php',
    ];

    private const array PUBLIC_PREFIXES = [
        '/_admin/',
        '/_assets/',
        '/_extensions/',
        '/_pictures/',
        '/_styles/',
    ];

    private const array PUBLIC_VENDOR_FILES = [
        '/_vendor/s2/admin-yard/demo/script.js',
        '/_vendor/s2/admin-yard/demo/style.css',
    ];

    private const array PUBLIC_EXTENSIONS = [
        '7z', 'avi', 'avif', 'bmp', 'css', 'csv', 'doc', 'docx', 'flac', 'flv', 'gif', 'html',
        'ico', 'jpeg', 'jpg', 'js', 'json', 'map', 'mkv', 'mov', 'mp3', 'mp4', 'mpeg', 'mpg',
        'odp', 'ods', 'odt', 'ogg', 'pdf', 'png', 'ppt', 'pptx', 'rar', 'rtf', 'svg', 'txt',
        'wasm', 'wav', 'webm', 'webp', 'woff', 'woff2', 'xls', 'xlsx', 'zip',
    ];

    public static function isAllowedPhpEndpoint(string $requestPath): bool
    {
        return \in_array($requestPath, self::PHP_ENDPOINTS, true);
    }

    public static function isAllowedStaticFile(string $requestPath, string $extension): bool
    {
        if (str_starts_with($requestPath, '/_cache/')) {
            return preg_match('#^/_cache/[a-z0-9_-]+\.[0-9a-f]+\.(?:css|js)(?:\.gz)?$#Di', $requestPath) === 1;
        }

        if (\in_array($requestPath, self::PUBLIC_VENDOR_FILES, true)) {
            return true;
        }

        if (!\in_array(\strtolower($extension), self::PUBLIC_EXTENSIONS, true)) {
            return false;
        }

        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if (\str_starts_with($requestPath, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
