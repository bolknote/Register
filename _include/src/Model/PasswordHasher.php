<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

final class PasswordHasher
{
    private const array ARGON2ID_OPTIONS = [
        'memory_cost' => 19 * 1024,
        'time_cost'   => 2,
        'threads'     => 1,
    ];

    private const array BCRYPT_OPTIONS = ['cost' => 12];

    private const string ARGON2ID_DUMMY_HASH = '$argon2id$v=19$m=19456,t=2,p=1$SVlVVDY1VU1Qam94bWJodQ$QbB2hRLgDd8L/LyqMKIbT5MKdA/C02/HgQFwj8iEZhk';

    private const string BCRYPT_DUMMY_HASH = '$2y$12$sEo8P2Bkb56lN9bNRbs6wuAsbAyQjeLBVR8Z1nzkLi03mGY.649mK';

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm(), self::options());
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    public static function dummyHash(): string
    {
        return self::usesArgon2id() ? self::ARGON2ID_DUMMY_HASH : self::BCRYPT_DUMMY_HASH;
    }

    private static function algorithm(): string
    {
        if (!self::usesArgon2id()) {
            return PASSWORD_DEFAULT;
        }

        return constant('PASSWORD_ARGON2ID');
    }

    /** @return array<string, int> */
    private static function options(): array
    {
        return self::usesArgon2id() ? self::ARGON2ID_OPTIONS : self::BCRYPT_OPTIONS;
    }

    private static function usesArgon2id(): bool
    {
        return \defined('PASSWORD_ARGON2ID');
    }
}
