<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Model;

use Codeception\Test\Unit;
use S2\Cms\Model\AuthTokenHasher;
use S2\Cms\Model\PasswordHasher;
use S2\Cms\Model\PasswordPolicy;

final class PasswordSecurityTest extends Unit
{
    public function testPasswordPolicyAcceptsLongPassphrases(): void
    {
        self::assertSame([], PasswordPolicy::violations('correct horse battery staple', 'alice'));
    }

    public function testPasswordPolicyRejectsWeakAndAccountDerivedPasswords(): void
    {
        self::assertContains('too_short', PasswordPolicy::violations('short'));
        self::assertContains('common', PasswordPolicy::violations('password1234'));
        self::assertContains('contains_login', PasswordPolicy::violations('alice-secure-passphrase', 'Alice'));
        self::assertContains('too_long', PasswordPolicy::violations(str_repeat('x', PasswordPolicy::MAX_LENGTH + 1)));
    }

    public function testPasswordHasherUsesCurrentAvailableAlgorithm(): void
    {
        $password = 'correct horse battery staple';
        $hash = PasswordHasher::hash($password);

        self::assertTrue(password_verify($password, $hash));
        self::assertFalse(PasswordHasher::needsRehash($hash));
        self::assertFalse(password_verify($password, PasswordHasher::dummyHash()));
    }

    public function testAuthenticationTokensAreStoredAsPurposeBoundHashes(): void
    {
        $token = str_repeat('a', 64);
        $sessionHash = AuthTokenHasher::session($token);
        $commentHash = AuthTokenHasher::comment($token);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $sessionHash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $commentHash);
        self::assertSame($sessionHash, AuthTokenHasher::session($token));
        self::assertNotSame($sessionHash, $commentHash);
        self::assertNotSame($token, $sessionHash);
    }
}
