<?php

declare(strict_types = 1);

namespace unit\Register;

use Codeception\Test\Unit;
use Register\Auth\PendingCommentRecovery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PendingCommentRecoveryTest extends Unit
{
    public function testRecoveryAndFormCredentialsAreBrowserAndDraftBound(): void
    {
        $request = Request::create('https://example.test/auth/check-email');
        $first = PendingCommentRecovery::create($request);
        $response = new Response();
        $first->remember($response, $request, '/blog/');
        $cookie = $response->headers->getCookies()[0];
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('/blog/', $cookie->getPath());
        $cookies = [PendingCommentRecovery::COOKIE_NAME => $cookie->getValue()];
        $restored = PendingCommentRecovery::fromRequest(Request::create('https://example.test/auth/check-email?draft=' . $first->draftId, cookies: $cookies));
        self::assertNotNull($restored);
        self::assertSame($first->hash(), $restored->hash());
        self::assertTrue($restored->matchesFormToken($first->formToken()));
        self::assertNotSame($first->hash(), $first->formToken());
        self::assertFalse($restored->matchesFormToken('forged'));
        self::assertNull(PendingCommentRecovery::fromRequest(Request::create('https://example.test/auth/check-email?draft=' . $first->draftId)));
        $other = PendingCommentRecovery::create(Request::create('https://example.test/', cookies: $cookies));
        self::assertNotSame($first->hash(), $other->hash());
        self::assertFalse($other->matchesFormToken($first->formToken()));
    }
}
