<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

/** Restricts post-authentication redirects to a local path on this installation. */
final class PublicReturnPath
{
    public static function normalize(string $path, string $fallback = '/'): string
    {
        $path = trim($path);
        if ($path === ''
            || strlen($path) > 1024
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
        ) {
            return $fallback;
        }

        $parsed = parse_url($path);
        if (!\is_array($parsed) || isset($parsed['scheme']) || isset($parsed['host']) || isset($parsed['user'])) {
            return $fallback;
        }

        return $path;
    }
}
