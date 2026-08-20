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
        self::assertTrue($registry->contains('service-worker.js'));
        self::assertTrue($registry->contains('TOPICS'));
        self::assertTrue($registry->contains('best'));
        self::assertFalse($registry->contains('ordinary-post'));
    }
}
