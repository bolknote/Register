<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Model;

final class AuthTokenHasher
{
    public static function session(string $token): string
    {
        return self::hash('admin-session', $token);
    }

    public static function comment(string $token): string
    {
        return self::hash('comment-session', $token);
    }

    private static function hash(string $purpose, string $token): string
    {
        // Tokens contain at least 256 random bits. A one-way storage key keeps a
        // copied database from being immediately usable as an authenticated cookie.
        return substr(hash('sha256', $purpose . "\0" . $token), 0, 32);
    }
}
