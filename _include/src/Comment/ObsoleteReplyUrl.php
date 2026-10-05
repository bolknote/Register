<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment;

/** Dependency-free so the public entry point can reject obsolete URLs before bootstrapping. */
final readonly class ObsoleteReplyUrl
{
    public const array QUERY_PARAMETERS = ['reply_to', 'reply_number', 'reply_name'];

    public const string BODY = '404 Not Found';

    public const array HEADERS = [
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'public, max-age=3600',
        'X-Robots-Tag' => 'noindex',
        'X-Content-Type-Options' => 'nosniff',
    ];

    /** @param array<string|int, mixed> $query */
    public static function matches(string $method, array $query): bool
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        foreach (self::QUERY_PARAMETERS as $parameter) {
            if (array_key_exists($parameter, $query)) {
                return true;
            }
        }

        return false;
    }
}
