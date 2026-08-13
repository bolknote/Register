<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Module\BaseModuleRegistry;
use Register\Schema\SchemaMigrator;
use S2\Cms\Extensions\ExtensionManager;
use S2\Cms\Framework\Container;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Pdo\DbLayer;

final class ModuleManagerCest
{
    public function baseModulesHaveNoOptionalLifecycle(\IntegrationTester $I): void
    {
        /** @var ExtensionManager $manager */
        $manager  = $I->grabAdminService(ExtensionManager::class);
        $registry = new BaseModuleRegistry();
        $list     = $manager->getExtensionList();

        $I->assertSame($registry->ids(), array_column($list['baseModules'], 'id'));
        $I->assertSame([], $list['availableExtensions']);
        $I->assertSame([], $list['installedExtensions']);
        $I->assertSame($registry->ids(), $manager->getEnabledExtensionIds());

        foreach ($registry->ids() as $id) {
            $I->assertNotNull($manager->flipExtension($id));
            $I->assertNotSame([], $manager->installExtension($id));
            $I->assertNotNull($manager->uninstallExtension($id));
        }

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Extension');
        $I->see('Built-in modules', 'h2');
        $I->assertCount(count($registry->ids()), $I->grabMultiple('.base-module'));
        $I->dontSeeElement('.base-module button');
    }

    public function registerSchemaUsesOneIntegerLedger(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        /** @var SchemaMigrator $schemaMigrator */
        $schemaMigrator = $I->grabAdminService(SchemaMigrator::class);

        $dbLayer->insert('extensions')->values([
            'id'             => ':id',
            'title'          => ':title',
            'version'        => ':version',
            'description'    => "''",
            'author'         => "''",
            'uninstall_note' => "''",
            'disabled'       => '0',
            'dependencies'   => "''",
        ])->execute([
            'id'      => BaseModuleRegistry::BLOG,
            'title'   => 'Legacy Blog',
            'version' => '2.0a3',
        ]);
        $I->setConfigValue(SchemaMigrator::CONFIG_KEY, '0');

        /** @var ExtensionCache $extensionCache */
        $extensionCache = $I->grabAdminService(ExtensionCache::class);
        $routesCache    = $extensionCache->getCachedRoutesFilename();
        if (file_put_contents($routesCache, '<?php return [];') === false) {
            throw new \RuntimeException('Unable to create the route-cache fixture.');
        }

        $I->assertTrue($schemaMigrator->migrate());
        $I->assertSame(SchemaMigrator::LATEST_REVISION, $schemaMigrator->currentRevision());
        $I->assertFileDoesNotExist($routesCache);
        $I->assertFalse($schemaMigrator->migrate());
        $I->assertTrue($dbLayer->fieldExists('art_comments', 'parent_id'));
        $I->assertTrue($dbLayer->indexExists('art_comments', 'thread_idx'));
        $I->assertTrue($dbLayer->fieldExists('s2_blog_comments', 'parent_id'));
        $I->assertTrue($dbLayer->indexExists('s2_blog_comments', 'thread_idx'));

        $legacyRows = $dbLayer->select('COUNT(*)')
            ->from('extensions')
            ->where('id = :id')->setParameter('id', BaseModuleRegistry::BLOG)
            ->execute()
            ->result()
        ;
        $I->assertSame(0, (int)$legacyRows);
    }

    public function baseManifestsCannotDestroyProductData(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer   = $I->grabAdminService(DbLayer::class);
        $container = new Container([]);

        foreach ([
            new \Register\Module\Blog\Manifest(),
            new \Register\Module\Search\Manifest(),
            new \Register\Module\Analytics\Manifest(),
        ] as $manifest) {
            $I->expectThrowable(\LogicException::class, static fn() => $manifest->uninstall($dbLayer, $container));
        }
    }
}
