<?php

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use Codeception\Attribute\DataProvider;
use PHPUnit\Framework\TestCase;
use Register\Auth\PublicAuthCacheRequest;
use Register\Auth\PublicAuthPageSnapshot;
use Symfony\Component\HttpFoundation\Response;

final class PublicAuthCacheRequestTest extends TestCase
{
    public function testReturnPathsShareOnlyTheirModeAndPreserveOtherRequestGuards(): void
    {
        $first = PublicAuthCacheRequest::fromUri('/blog/auth?mode=email&return=%2Ffirst', '/blog/auth');
        $other = PublicAuthCacheRequest::fromUri('/blog/auth?return=%2Fsecond&mode=email', '/blog/auth');
        self::assertNotNull($first);
        self::assertNotNull($other);
        $server = ['REQUEST_URI' => '/original', 'HTTP_HOST' => 'example.test', 'HTTP_COOKIE' => 'session=private',
            'HTTP_AUTHORIZATION' => 'Bearer private', 'REQUEST_METHOD' => 'POST', 'REGISTER_EARLY_VARIANT' => 'crawler'];
        self::assertSame($first->cacheServer($server), $other->cacheServer($server));
        self::assertSame('/blog/auth', $first->cacheServer($server)['REQUEST_URI']);
        self::assertSame('session=private', $first->cacheServer($server)['HTTP_COOKIE']);
        self::assertSame('Bearer private', $first->cacheServer($server)['HTTP_AUTHORIZATION']);
        self::assertSame('POST', $first->cacheServer($server)['REQUEST_METHOD']);
        $password = PublicAuthCacheRequest::fromUri('/blog/auth?mode=password', '/blog/auth');
        self::assertNotNull($password);
        self::assertNotSame($first->cacheServer($server), $password->cacheServer($server));
        $plain = PublicAuthCacheRequest::fromUri('/blog/auth', '/blog/auth');
        $query = PublicAuthCacheRequest::fromUri('/blog/auth?return=%2F', '/blog/auth');
        self::assertNotNull($plain);
        self::assertNotNull($query);
        self::assertNotSame($plain->cacheServer($server), $query->cacheServer($server));
    }

    #[DataProvider('unsafeRequests')]
    public function testUnknownOrAmbiguousRequestsFallBackToTheEngine(string $uri, string $authPath = '/auth'): void
    {
        self::assertNull(PublicAuthCacheRequest::fromUri($uri, $authPath));
    }

    /** @return iterable<string,array{0:string,1?:string}> */
    public static function unsafeRequests(): iterable
    {
        yield 'ordinary article' => ['/article?return=/'];
        yield 'oauth callback' => ['/auth/oauth/vk/callback?code=private&state=private'];
        yield 'email callback' => ['/auth/email/callback?token=private'];
        yield 'unknown query' => ['/auth?token=private'];
        yield 'array return' => ['/auth?return[]=private'];
        yield 'array mode' => ['/auth?mode[]=email'];
        yield 'provider with extra mode' => ['/auth/oauth/vk?mode=email'];
        yield 'trailing slash' => ['/auth/?return=/'];
        yield 'different mount' => ['/other/auth', '/blog/auth'];
        yield 'query based routing' => ['/index.php?/auth&return=/', '/index.php?/auth'];
        yield 'control' => ["/auth?return=/a\r\nHeader:x"];
        yield 'encoded control' => ['/auth?return=%2Fa%0d%0a'];
        yield 'fragment in request' => ['/auth#fragment'];
        yield 'oversized request' => ['/auth?return=/' . str_repeat('x', 2048)];
    }

    public function testReturnSubstitutionEscapesHtmlAndUrlsWithoutRecursiveReplacement(): void
    {
        $return = '/article?a="<script>&b=1#comment';
        $input = PublicAuthCacheRequest::fromUri('/auth?return=' . rawurlencode($return), '/auth');
        self::assertNotNull($input);
        $template = '<input value="' . PublicAuthCacheRequest::RETURN_MARKER . '"><a href="/auth?return='
            . rawurlencode(PublicAuthCacheRequest::RETURN_MARKER) . '">sign in</a>';
        self::assertSame('<input value="/article?a=&quot;&lt;script&gt;&amp;b=1#comment"><a href="/auth?return='
            . rawurlencode($return) . '">sign in</a>', $input->expand($template));
        foreach (['//outside.test', 'https://outside.test/', '', '/bad\\path'] as $bad) {
            $input = PublicAuthCacheRequest::fromUri('/auth?mode=unknown&return=' . rawurlencode($bad), '/auth');
            self::assertNotNull($input);
            self::assertSame('/', $input->returnPath);
            self::assertSame('methods', $input->mode);
        }
    }

    public function testSnapshotOnlyPublishesTheUnmodifiedAnonymousTemplate(): void
    {
        $parameters = PublicAuthCacheRequest::fromUri('/auth?return=%2Farticle', '/auth');
        self::assertNotNull($parameters);
        $template = '<input value="' . PublicAuthCacheRequest::RETURN_MARKER . '">';
        $snapshot = new PublicAuthPageSnapshot($parameters, $template);
        $response = new Response($parameters->expand($template));
        self::assertSame($template, $snapshot->publicResponse($response)->getContent());
        self::assertSame('<input value="/article">', $response->getContent());
        $response->setContent('An extension inserted request-specific information.');
        self::assertTrue($snapshot->publicResponse($response)->headers->hasCacheControlDirective('no-store'));
    }
}
