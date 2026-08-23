<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Url;

use Codeception\Test\Unit;
use Register\Url\ReservedRouteRegistry;

final class ReservedRouteRegistryTest extends Unit
{
    public function testContainsFixedAndConfiguredRootSegments(): void
    {
        $registry = new ReservedRouteRegistry('topics', 'best');

        self::assertTrue($registry->contains('all'));
        self::assertTrue($registry->contains('archive'));
        self::assertTrue($registry->contains('_admin'));
        self::assertTrue($registry->contains('activitypub'));
        self::assertTrue($registry->contains('feed.json'));
        self::assertTrue($registry->contains('popular'));
        self::assertTrue($registry->contains('hot'));
        self::assertTrue($registry->contains('random'));
        self::assertTrue($registry->contains('rss'));
        self::assertTrue($registry->contains('rss.xml'));
        self::assertTrue($registry->contains('service-worker.js'));
        self::assertTrue($registry->contains('TOPICS'));
        self::assertTrue($registry->contains('best'));
        self::assertFalse($registry->contains('ordinary-post'));
    }
}
