<?php

declare(strict_types = 1);

namespace unit\Register\Comment;

use PHPUnit\Framework\TestCase;
use Register\Core\Comment\CommentFormCacheSnapshot;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class CommentFormCacheSnapshotTest extends TestCase
{
    public function testOnlyTheSnapshotLosesThePrivateFormAndItsOwnCookie(): void
    {
        $cookie = Cookie::create('site_antispam', 'private');
        $response = new Response('<article>Public</article><form>Private token</form>');
        $response->headers->setCookie($cookie);
        $snapshot = new CommentFormCacheSnapshot([['form' => '<form>Private token</form>', 'placeholder' => '<a>Load form</a>']], $cookie);
        $public = $snapshot->publicResponse($response);
        self::assertSame('<article>Public</article><a>Load form</a>', $public->getContent());
        self::assertSame([], $public->headers->getCookies());
        self::assertSame('<article>Public</article><form>Private token</form>', $response->getContent());
        self::assertSame([$cookie], $response->headers->getCookies());

        $response->headers->setCookie(Cookie::create('other_session', 'secret'));
        self::assertCount(1, $snapshot->publicResponse($response)->headers->getCookies());
    }

    public function testChangedFormsOrCookiesFailClosedInsteadOfPublishingPrivateState(): void
    {
        $cookie = Cookie::create('site_antispam', 'private');
        $snapshot = new CommentFormCacheSnapshot([['form' => '<form>Private</form>', 'placeholder' => 'Public']], $cookie);
        foreach ([
            ['<form>Changed</form>', $cookie],
            ['<form>Private</form><form>Private</form>', $cookie],
            ['<form>Private</form>', Cookie::create('site_antispam', 'changed')],
            ['<form>Private</form>', null],
        ] as [$body, $actualCookie]) {
            $response = new Response($body);
            if ($actualCookie instanceof Cookie) {
                $response->headers->setCookie($actualCookie);
            }
            self::assertTrue($snapshot->publicResponse($response)->headers->hasCacheControlDirective('no-store'));
        }
    }
}
