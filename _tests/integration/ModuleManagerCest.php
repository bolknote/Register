<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Module\BaseModuleRegistry;
use Register\Comment\CommentSchema;
use Register\Content\ContentTagSchema;
use Register\Schema\SchemaMigrator;
use S2\Cms\Extensions\ExtensionManager;
use S2\Cms\Extensions\ManifestInterface;
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
        $I->assertTrue($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'parent_id'));
        $I->assertTrue($dbLayer->indexExists(CommentSchema::TABLE_NAME, 'thread_idx'));
        $I->assertTrue($dbLayer->tableExists('userpics'));
        $I->assertTrue($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'userpic_id'));
        $I->assertTrue($dbLayer->indexExists(CommentSchema::TABLE_NAME, 'userpic_idx'));
        $I->assertTrue($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'deleted'));
        $I->assertFalse($dbLayer->tableExists('art_comments'));
        $I->assertFalse($dbLayer->tableExists('s2_blog_comments'));
        $I->assertTrue($dbLayer->fieldExists('s2_blog_posts', 'display_date'));
        $I->assertTrue($dbLayer->tableExists(ContentTagSchema::TABLE_NAME));
        $I->assertTrue($dbLayer->indexExists(ContentTagSchema::TABLE_NAME, 'content_tag_idx'));
        $I->assertTrue($dbLayer->indexExists(ContentTagSchema::TABLE_NAME, 'tag_content_idx'));
    }

    public function baseManifestsDoNotExposeOptionalLifecycle(\IntegrationTester $I): void
    {
        $registry = new BaseModuleRegistry();
        foreach ($registry->ids() as $id) {
            $manifestClass = $registry->manifestClass($id);
            $I->assertFalse(is_a($manifestClass, ManifestInterface::class, true));
            $I->assertFalse((new \ReflectionClass($manifestClass))->hasMethod('uninstall'));
        }
    }
}
