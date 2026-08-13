<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Model;

use Codeception\Test\Unit;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Pdo\DbLayer;

final class ExtensionCacheTest extends Unit
{
    public function testOmitsModulesAlreadyLoadedByProductKernel(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE extensions (id VARCHAR(255) NOT NULL, disabled INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO extensions (id, disabled) VALUES ('s2_blog', 0), ('s2_search', 0)");

        $cache = new ExtensionCache(new DbLayer($pdo), true, '');

        self::assertSame([
            'cms' => ['\s2_extensions\s2_search\Extension'],
            'admin' => ['\s2_extensions\s2_search\AdminExtension'],
        ], $cache->generateEnabledExtensionClassNames(['s2_blog']));
    }
}
