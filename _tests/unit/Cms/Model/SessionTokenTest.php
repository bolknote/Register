<?php

declare(strict_types = 1);

namespace unit\Cms\Model;

use Codeception\Test\Unit;
use Register\Core\Model\SessionToken;

final class SessionTokenTest extends Unit
{
    public function testAbsoluteAndIdleLimitsAreIndependent(): void
    {
        $issuedAt = 1_700_000_000;
        foreach ([[true, 12 * 3600, 30 * 60], [false, 30 * 86400, 7 * 86400]] as [$temporary, $lifetime, $idle]) {
            $token = SessionToken::create($temporary, $issuedAt);
            self::assertSame($issuedAt, SessionToken::issuedAt($token, $issuedAt));
            self::assertTrue(SessionToken::isActive($token, $issuedAt, $issuedAt + $idle - 1));
            self::assertFalse(SessionToken::isActive($token, $issuedAt, $issuedAt + $idle));
            self::assertTrue(SessionToken::isActive($token, $issuedAt + $lifetime - 1, $issuedAt + $lifetime - 1));
            self::assertFalse(SessionToken::isActive($token, $issuedAt + $lifetime, $issuedAt + $lifetime));
        }
    }

    public function testUnverifiableAndFutureIssuedTokensFailClosed(): void
    {
        $now = 1_700_000_000;
        foreach (['', str_repeat('a', 64), 'p1234', SessionToken::create(false, $now + 301)] as $token) {
            self::assertFalse(SessionToken::isActive($token, $now, $now));
        }
    }
}
