<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Domain;

/** A normalized, already URL-encoded installation path; the origin root is the empty string. */
final readonly class CanonicalBasePath
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = rtrim(trim($value), '/');
        if ($normalized === '/') {
            $normalized = '';
        }

        if ($normalized !== '' && (!str_starts_with($normalized, '/')
            || str_contains($normalized, '//')
            || preg_match('~(?:^|/)(?:\.{1,2})(?:/|$)~', $normalized) === 1
            || preg_match('/[?#\x00-\x20\x7f]/', $normalized) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $normalized) === 1
        )) {
            throw new \InvalidArgumentException('The ActivityPub base path must be an absolute, encoded URL path without a trailing slash.');
        }

        $this->value = $normalized;
    }
}
