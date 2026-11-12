<?php

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;
use Register\Core\Http\Cache\EarlyPageCache;
use Register\Core\Http\Cache\PublicPageCacheRequest;

final class PublicPageCacheRequestTest extends TestCase
{
    public function testOnlyExactPaginationIsEligibleAndEachPageHasItsOwnVariant(): void
    {
        $server = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/blog/topic/?p=2',
            'REGISTER_EARLY_VARIANT' => 'crawler'];
        $page = PublicPageCacheRequest::fromUri($server['REQUEST_URI']);
        self::assertNotNull($page);
        self::assertTrue(EarlyPageCache::accepts($page->cacheServer($server)));
        self::assertSame('crawler:page:2', $page->cacheServer($server)['REGISTER_EARLY_VARIANT']);
        self::assertSame('/blog/topic/', $page->cacheServer($server)['REQUEST_URI']);
        foreach (['/topic/', '/topic/?p=0', '/topic/?p=02', '/topic/?p=-1', '/topic/?p[]=2',
            '/topic/?p=2&p=3', '/topic/?p=2&return=private', '/topic/?p=1000000000', '/topic/?p=%32', '/topic/?q=2'] as $uri) {
            self::assertNull(PublicPageCacheRequest::fromUri($uri), $uri);
        }
    }
}
