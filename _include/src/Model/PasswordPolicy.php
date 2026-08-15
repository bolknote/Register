<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;

    public const int MAX_LENGTH = 255;

    /** @var list<string> */
    private const array COMMON_PASSWORDS = [
        '111111111111', '123123123123', '123456789012', '123456789123', '1q2w3e4r5t6y',
        'administrator', 'adminadmin', 'admin123456', 'changeme1234', 'iloveyou123',
        'letmein12345', 'password1234', 'password12345', 'qwerty123456', 'qwertyuiop12',
        'welcome12345',
    ];

    /**
     * @return list<'common'|'contains_login'|'too_long'|'too_short'>
     */
    public static function violations(string $password, string $login = ''): array
    {
        $violations = [];
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            $violations[] = 'too_short';
        }

        if ($length > self::MAX_LENGTH) {
            $violations[] = 'too_long';
        }

        $normalized = mb_strtolower(trim($password));
        if (
            \in_array($normalized, self::COMMON_PASSWORDS, true)
            || preg_match('/^(?:admin|letmein|password|qwerty|welcome)[!@#$%^&*._-]*[0-9]{0,8}$/D', $normalized) === 1
            || preg_match('/^(.)\1{11,}$/Du', $normalized) === 1
        ) {
            $violations[] = 'common';
        }

        $normalizedLogin = mb_strtolower(trim($login));
        if ($normalizedLogin !== '' && mb_strlen($normalizedLogin) >= 3 && str_contains($normalized, $normalizedLogin)) {
            $violations[] = 'contains_login';
        }

        return array_values(array_unique($violations));
    }
}
