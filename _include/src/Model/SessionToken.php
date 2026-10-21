<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Model;

/** Shared lifetime policy for independently hashed admin and public session tokens. */
final readonly class SessionToken
{
    public const int PERSISTENT_LIFETIME = 30 * 86400;

    private const int PERSISTENT_IDLE_TIMEOUT = 7 * 86400;

    private const int TEMPORARY_LIFETIME = 12 * 3600;

    private const int TEMPORARY_IDLE_TIMEOUT = 30 * 60;

    private const string PATTERN = '/^([pt])([0-9a-f]{8})[0-9a-f]{64}$/D';

    public static function create(bool $temporary, int $issuedAt): string
    {
        return ($temporary ? 't' : 'p') . sprintf('%08x', $issuedAt) . bin2hex(random_bytes(32));
    }

    public static function issuedAt(string $token, int $now): ?int
    {
        if (preg_match(self::PATTERN, $token, $matches) !== 1) {
            return null;
        }

        $issuedAt = (int)hexdec($matches[2]);

        return $issuedAt > $now + 300 ? null : $issuedAt;
    }

    /** The token must also match its stored hash; its timestamp alone is not authentication. */
    public static function isActive(string $token, int $lastActivity, int $now): bool
    {
        $issuedAt = self::issuedAt($token, $now);
        $persistent = str_starts_with($token, 'p');
        $lifetime = $persistent ? self::PERSISTENT_LIFETIME : self::TEMPORARY_LIFETIME;
        $idleTimeout = $persistent ? self::PERSISTENT_IDLE_TIMEOUT : self::TEMPORARY_IDLE_TIMEOUT;

        return $issuedAt !== null && $now < $issuedAt + $lifetime && $now < $lastActivity + $idleTimeout;
    }
}
