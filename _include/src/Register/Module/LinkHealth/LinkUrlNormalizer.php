<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

/** Normalizes only equivalences that are safe for reachability checks. */
final readonly class LinkUrlNormalizer
{
    private const array ARCHIVE_HOSTS = [
        'web.archive.org',
        'wayback.archive-it.org',
    ];

    private string $siteScheme;

    private string $siteHost;

    private int $sitePort;

    private string $basePath;

    public function __construct(string $baseUrl, string $basePath)
    {
        $parsed = $this->parseUri($baseUrl);
        if (!\is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException('The canonical base URL must contain a scheme and host.');
        }

        $scheme = strtolower($parsed['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('The canonical base URL must use HTTP or HTTPS.');
        }

        $this->siteScheme = $scheme;
        $this->siteHost   = $this->normalizeHost(rawurldecode($parsed['host']));
        $this->sitePort   = $parsed['port'] ?? $this->defaultPort($scheme);

        $basePath = trim($basePath);
        if ($basePath === '' || $basePath === '/') {
            $this->basePath = '';
        } else {
            $this->basePath = rtrim($this->canonicalLocalPath($basePath), '/');
        }
    }

    public function normalize(string $href, string $sourcePath): ?NormalizedLink
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        if (preg_match('/[\x00-\x20\x7f]/', $href) === 1 || str_contains($href, '\\')) {
            return null;
        }

        $parsedHref = $this->parseUri($href);
        $scheme = \is_array($parsedHref) ? ($parsedHref['scheme'] ?? null) : null;
        if (\is_string($scheme) && $scheme !== '') {
            if (!\in_array(strtolower($scheme), ['http', 'https'], true)) {
                return null;
            }

            return $this->normalizeAbsolute($href);
        }

        if (str_starts_with($href, '//')) {
            return $this->normalizeAbsolute($this->siteScheme . ':' . $href);
        }

        $parsed = $parsedHref;
        if (!\is_array($parsed)) {
            return null;
        }

        $fragment = $parsed['fragment'] ?? '';
        $path     = $parsed['path'] ?? '';
        if (str_starts_with($href, '/')) {
            $sitePath = $this->canonicalLocalPath($path === '' ? '/' : $path);
            $local    = $this->stripBasePath($sitePath);
            if ($local !== null) {
                return new NormalizedLink($local, LinkKind::LOCAL, $this->siteHost, $fragment);
            }

            $absolute = $this->origin() . $sitePath;
            if (isset($parsed['query'])) {
                $absolute .= '?' . $parsed['query'];
            }

            return new NormalizedLink($absolute, LinkKind::EXTERNAL, $this->siteHost, $fragment);
        }

        $sourcePath = $this->canonicalLocalPath($sourcePath);
        $targetPath = $path === ''
            ? $sourcePath
            : $this->resolveRelativePath($sourcePath, $path);

        return new NormalizedLink($targetPath, LinkKind::LOCAL, $this->siteHost, $fragment);
    }

    public function canonicalLocalPath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $trailingSlash = str_ends_with($path, '/');
        $segments      = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            $decoded = rawurldecode($segment);
            if ($decoded === '.') {
                continue;
            }

            if ($decoded === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = rawurlencode($decoded);
        }

        $normalized = '/' . implode('/', $segments);
        if ($normalized !== '/' && $trailingSlash) {
            $normalized .= '/';
        }

        return $normalized;
    }

    private function normalizeAbsolute(string $url): ?NormalizedLink
    {
        $parsed = $this->parseUri($url);
        if (!\is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return null;
        }

        $scheme = strtolower($parsed['scheme']);
        $host   = $this->normalizeHost(rawurldecode($parsed['host']));
        $port   = $parsed['port'] ?? $this->defaultPort($scheme);
        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        $path     = isset($parsed['path']) && $parsed['path'] !== '' ? $parsed['path'] : '/';
        $fragment = $parsed['fragment'] ?? '';
        $sameSitePort = !isset($parsed['port']) || $port === $this->sitePort;
        if ($host === $this->siteHost && $sameSitePort) {
            $local = $this->stripBasePath($this->canonicalLocalPath($path));
            if ($local !== null) {
                return new NormalizedLink($local, LinkKind::LOCAL, $host, $fragment);
            }
        }

        $normalized = $scheme . '://' . $this->formatHost($host);
        if ($port !== $this->defaultPort($scheme)) {
            $normalized .= ':' . $port;
        }

        $normalized .= $path;
        if (isset($parsed['query'])) {
            $normalized .= '?' . $parsed['query'];
        }

        $kind = \in_array($host, self::ARCHIVE_HOSTS, true)
            || ($host === 'archive.org' && str_starts_with($path, '/wayback/'))
            ? LinkKind::ARCHIVE
            : LinkKind::EXTERNAL;

        return new NormalizedLink($normalized, $kind, $host, $fragment);
    }

    private function resolveRelativePath(string $sourcePath, string $relativePath): string
    {
        $directory = str_ends_with($sourcePath, '/')
            ? $sourcePath
            : substr($sourcePath, 0, (int)strrpos($sourcePath, '/') + 1);

        return $this->canonicalLocalPath($directory . $relativePath);
    }

    private function stripBasePath(string $sitePath): ?string
    {
        if ($this->basePath === '') {
            return $sitePath;
        }

        if ($sitePath === $this->basePath) {
            return '/';
        }

        if (!str_starts_with($sitePath, $this->basePath . '/')) {
            return null;
        }

        return substr($sitePath, \strlen($this->basePath));
    }

    private function normalizeHost(string $host): string
    {
        $host = trim(strtolower($host), '[]');
        if ($host === '' || !\function_exists('idn_to_ascii')) {
            return $host;
        }

        $variant = \defined('INTL_IDNA_VARIANT_UTS46') ? \constant('INTL_IDNA_VARIANT_UTS46') : 0;
        $ascii   = idn_to_ascii($host, 0, $variant);

        return \is_string($ascii) ? strtolower($ascii) : $host;
    }

    /**
     * PHP's parse_url() replaces some raw UTF-8 continuation bytes with underscores. Percent-encode
     * every non-ASCII byte first, then decode only the hostname for IDNA handling; paths and queries
     * remain canonical ASCII URLs suitable for every supported database.
     *
     * @return array{
     *     scheme?: string,
     *     host?: string,
     *     port?: int,
     *     user?: string,
     *     pass?: string,
     *     path?: string,
     *     query?: string,
     *     fragment?: string
     * }|false
     */
    private function parseUri(string $uri): array|false
    {
        $ascii = preg_replace_callback(
            '/[\x80-\xFF]/',
            static fn(array $match): string => sprintf('%%%02X', ord($match[0])),
            $uri,
        );
        if (!\is_string($ascii)) {
            return false;
        }

        return parse_url($ascii);
    }

    private function origin(): string
    {
        $origin = $this->siteScheme . '://' . $this->formatHost($this->siteHost);
        if ($this->sitePort !== $this->defaultPort($this->siteScheme)) {
            $origin .= ':' . $this->sitePort;
        }

        return $origin;
    }

    private function formatHost(string $host): string
    {
        return str_contains($host, ':') ? '[' . $host . ']' : $host;
    }

    private function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }
}
