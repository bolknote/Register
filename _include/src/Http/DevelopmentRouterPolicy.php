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
        '/_extensions/s2_counter/counter.php',
        '/_extensions/s2_counter/data.php',
    ];

    private const array PUBLIC_PREFIXES = [
        '/_admin/',
        '/_cache/',
        '/_extensions/',
        '/_pictures/',
        '/_styles/',
    ];

    private const array PUBLIC_VENDOR_FILES = [
        '/_vendor/s2/admin-yard/demo/script.js',
        '/_vendor/s2/admin-yard/demo/style.css',
    ];

    private const array PUBLIC_EXTENSIONS = [
        'avif', 'bmp', 'css', 'gif', 'html', 'ico', 'jpeg', 'jpg', 'js', 'json', 'map',
        'mp3', 'mp4', 'ogg', 'png', 'svg', 'wasm', 'wav', 'webm', 'webp', 'woff', 'woff2',
    ];

    public static function isAllowedPhpEndpoint(string $requestPath): bool
    {
        return \in_array($requestPath, self::PHP_ENDPOINTS, true);
    }

    public static function isAllowedStaticFile(string $requestPath, string $extension): bool
    {
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
