<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Security\Http;

use Symfony\Component\HttpFoundation\Request;

/** Adds an origin boundary to cookie-authenticated state-changing requests. */
final class SameOriginRequestGuard
{
    public function violation(Request $request, bool $requireBrowserEvidence = false): ?string
    {
        if ($request->isMethodSafe()) {
            return null;
        }

        $fetchSite = strtolower($request->headers->get('Sec-Fetch-Site', '') ?? '');
        if (\in_array($fetchSite, ['cross-site', 'same-site'], true)
            || ($requireBrowserEvidence && $fetchSite !== '' && $fetchSite !== 'same-origin')
        ) {
            return 'A same-origin request is required.';
        }

        $source = $request->headers->get('Origin');
        if ($source === null || $source === '') {
            $source = $request->headers->get('Referer');
        }

        // Synchronizer tokens can support older clients without these headers.
        // Shared public form tokens instead require independent browser evidence.
        if ($source === null || $source === '') {
            return $requireBrowserEvidence && $fetchSite !== 'same-origin'
                ? 'A same-origin request is required.'
                : null;
        }

        $sourceOrigin = $this->normalizeOrigin($source);
        $targetOrigin = $this->normalizeOrigin($request->getSchemeAndHttpHost());
        if ($sourceOrigin === null || $targetOrigin === null || !hash_equals($targetOrigin, $sourceOrigin)) {
            return 'The request origin is not allowed.';
        }

        return null;
    }

    private function normalizeOrigin(string $url): ?string
    {
        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            return null;
        }

        if (!\is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'])
            || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($host === '' || preg_match('/[\x00-\x20\x7f]/', $host) === 1) {
            return null;
        }

        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $port = $parts['port'] ?? null;
        if (($port === 80 && $scheme === 'http') || ($port === 443 && $scheme === 'https')) {
            $port = null;
        }

        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    }
}
