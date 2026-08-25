<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http;

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
        '/files/',
        '/_pictures/',
        '/_styles/',
    ];

    private const array PUBLIC_ROOT_FILES = [
        '/service-worker.js',
        '/site.webmanifest',
    ];

    private const array PUBLIC_EXTENSIONS = [
        '7z', 'avi', 'avif', 'bmp', 'bpg', 'css', 'csv', 'cur', 'doc', 'docx', 'emf', 'flac',
        'flv', 'gif', 'html', 'ico', 'jpeg', 'jpeg2000', 'jpegxr', 'jpg', 'js', 'json', 'map',
        'mkv', 'mng', 'mov', 'mp3', 'mp4', 'mpeg', 'mpg', 'odp', 'ods', 'odt', 'ogg', 'pdf',
        'png', 'ppt', 'pptx', 'rar', 'rtf', 'svg', 'tiff', 'txt', 'wasm', 'wav', 'wbmp', 'webm',
        'webp', 'wmf', 'woff', 'woff2', 'xbm', 'xls', 'xlsx', 'zip',
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

        if (\in_array($requestPath, self::PUBLIC_ROOT_FILES, true)) {
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
