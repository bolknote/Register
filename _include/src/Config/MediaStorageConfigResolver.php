<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Config;

/** Resolves the upload filesystem directory independently from its public URL. */
final class MediaStorageConfigResolver
{
    /** @return array{directory: string, url: ?string} */
    public static function resolve(
        string $publicRoot,
        ?string $configuredDirectory,
        ?string $configuredUrl,
        ?string $basePath,
    ): array {
        $publicRoot = rtrim(trim($publicRoot), '/\\');
        if ($publicRoot === '') {
            throw new \InvalidArgumentException('The public root directory cannot be empty.');
        }

        $configuredDirectory = trim($configuredDirectory ?? '');
        if ($configuredDirectory === '') {
            $configuredDirectory = StaticConfigLoader::DEFAULT_IMAGE_DIR;
        }
        self::rejectControlCharacters($configuredDirectory, 'media storage directory');

        $absoluteDirectory = self::isAbsolutePath($configuredDirectory);
        if ($absoluteDirectory) {
            $directory = rtrim($configuredDirectory, '/\\');
        } else {
            $segments = preg_split('#[\\\\/]+#', trim($configuredDirectory, '/\\'), -1, PREG_SPLIT_NO_EMPTY);
            if ($segments === false || $segments === [] || \in_array('.', $segments, true) || \in_array('..', $segments, true)) {
                throw new \InvalidArgumentException('The relative media storage directory must not contain dot segments.');
            }

            $relativeDirectory = implode('/', $segments);
            $directory         = $publicRoot . '/' . $relativeDirectory;
        }

        $configuredUrl = trim($configuredUrl ?? '');
        if ($configuredUrl !== '') {
            return [
                'directory' => $directory,
                'url'       => self::normalizeUrl($configuredUrl),
            ];
        }

        if ($absoluteDirectory) {
            throw new \InvalidArgumentException('An absolute media storage directory requires files.image_url.');
        }

        if ($basePath === null) {
            return ['directory' => $directory, 'url' => null];
        }

        self::rejectControlCharacters($basePath, 'base path');
        $basePath = rtrim($basePath, '/');

        return [
            'directory' => $directory,
            'url'       => $basePath . '/' . $relativeDirectory,
        ];
    }

    private static function normalizeUrl(string $url): string
    {
        self::rejectControlCharacters($url, 'media URL');
        if (str_contains($url, '\\')) {
            throw new \InvalidArgumentException('The media URL must not contain backslashes.');
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            if (str_contains($url, '?') || str_contains($url, '#')) {
                throw new \InvalidArgumentException('The media URL must not contain a query string or fragment.');
            }

            return rtrim($url, '/');
        }

        if (preg_match('#^https://#i', $url) !== 1) {
            throw new \InvalidArgumentException('The external media URL must use HTTPS.');
        }

        $parts = parse_url($url);
        if (!\is_array($parts)
            || strtolower(\is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('The external media URL must contain only an HTTPS origin and path.');
        }

        $host = strtolower($parts['host']);
        if (strlen($host) > 253 || !self::isValidHost($host)) {
            throw new \InvalidArgumentException('The external media URL contains an invalid host.');
        }

        $port = $parts['port'] ?? null;
        if ($port === 0) {
            throw new \InvalidArgumentException('The external media URL contains an invalid port.');
        }

        $path = \is_string($parts['path'] ?? null) ? rtrim($parts['path'], '/') : '';

        return 'https://' . $host . ($port === null || $port === 443 ? '' : ':' . $port) . $path;
    }

    private static function rejectControlCharacters(string $value, string $label): void
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException('The ' . $label . ' must not contain control characters.');
        }
    }

    private static function isValidHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }
}
