<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Model;

use Codeception\Test\Unit;
use Register\Module\BaseModuleRegistry;
use Register\Core\Model\ExtensionCache;
use Register\Core\Pdo\DbLayer;

final class ExtensionCacheTest extends Unit
{
    public function testOmitsModulesAlreadyLoadedByProductKernel(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE extensions (id VARCHAR(255) NOT NULL, disabled INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO extensions (id, disabled) VALUES ('register_blog', 0), ('register_search', 0)");

        $cache = new ExtensionCache(new DbLayer($pdo), true, '');

        self::assertSame([
            'cms' => [],
            'admin' => [],
        ], $cache->generateEnabledExtensionClassNames((new BaseModuleRegistry())->ids()));
    }
}
