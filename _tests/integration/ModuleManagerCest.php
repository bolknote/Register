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
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Url\ContentUrlAliasSchema;
use Register\Schema\SchemaManager;
use S2\Cms\Extensions\ExtensionManager;
use S2\Cms\Extensions\ManifestInterface;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Pdo\DbLayer;

final class ModuleManagerCest
{
    /** @var list<string> */
    private const array OBSOLETE_PRODUCT_TABLES = [
        'articles',
        'art_comments',
        'article_tag',
        's2_blog_posts',
        's2_blog_comments',
        's2_blog_post_tag',
    ];

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
        $I->amOnPage('https://localhost/_admin/index.php?entity=SystemModules');
        $I->see('System modules', 'h1');
        $I->assertCount(count($registry->ids()), $I->grabMultiple('.base-module'));
        $I->dontSeeElement('.base-module button');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Extension');
        $I->dontSeeElement('.base-module');
    }

    public function registerSchemaUsesOneCleanGeneration(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        /** @var SchemaManager $schemaManager */
        $schemaManager = $I->grabAdminService(SchemaManager::class);

        $I->setConfigValue(SchemaManager::CONFIG_KEY, '0');

        /** @var ExtensionCache $extensionCache */
        $extensionCache = $I->grabAdminService(ExtensionCache::class);
        $routesCache    = $extensionCache->getCachedRoutesFilename();
        if (file_put_contents($routesCache, '<?php return [];') === false) {
            throw new \RuntimeException('Unable to create the route-cache fixture.');
        }

        $I->assertTrue($schemaManager->ensureCurrent());
        $I->assertSame(SchemaManager::CURRENT_GENERATION, $schemaManager->currentGeneration());
        $I->assertFileDoesNotExist($routesCache);
        $I->assertFalse($schemaManager->ensureCurrent());
        $I->assertTrue($dbLayer->tableExists(ContentSchema::TABLE_NAME));
        $I->assertTrue($dbLayer->fieldExists(ContentSchema::TABLE_NAME, 'content_type'));
        $I->assertTrue($dbLayer->fieldExists(ContentSchema::TABLE_NAME, 'body'));
        $I->assertTrue($dbLayer->indexExists(ContentSchema::TABLE_NAME, 'type_parent_sort_idx'));
        $I->assertTrue($dbLayer->indexExists(ContentSchema::TABLE_NAME, 'type_publication_idx'));
        $I->assertTrue($dbLayer->fieldExists(ContentSchema::TABLE_NAME, 'scheduled_at'));
        $I->assertTrue($dbLayer->indexExists(ContentSchema::TABLE_NAME, 'scheduled_publication_idx'));
        $I->assertTrue($dbLayer->fieldExists(ContentSchema::TABLE_NAME, 'slug_scope'));
        $I->assertTrue($dbLayer->indexExists(ContentSchema::TABLE_NAME, 'slug_scope_idx'));
        $I->assertTrue($dbLayer->foreignKeyExists(ContentSchema::TABLE_NAME, 'fk_parent'));
        $I->assertTrue($dbLayer->foreignKeyExists(ContentSchema::TABLE_NAME, 'fk_author'));
        $I->assertTrue($dbLayer->tableExists(ContentUrlAliasSchema::TABLE_NAME));
        $I->assertTrue($dbLayer->indexExists(ContentUrlAliasSchema::TABLE_NAME, 'content_idx'));
        $I->assertTrue($dbLayer->foreignKeyExists(
            ContentUrlAliasSchema::TABLE_NAME,
            'fk_content_url_alias_content',
        ));
        $I->assertTrue($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'parent_id'));
        $I->assertTrue($dbLayer->indexExists(CommentSchema::TABLE_NAME, 'thread_idx'));
        $I->assertTrue($dbLayer->tableExists('userpics'));
        $I->assertTrue($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'userpic_id'));
        $I->assertTrue($dbLayer->indexExists(CommentSchema::TABLE_NAME, 'userpic_idx'));
        $I->assertTrue($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'deleted'));
        foreach (self::OBSOLETE_PRODUCT_TABLES as $obsoleteTable) {
            $I->assertFalse(
                $dbLayer->tableExists($obsoleteTable),
                sprintf('Obsolete product table "%s" must not be created.', $obsoleteTable),
            );
        }

        $I->assertTrue($dbLayer->fieldExists(ContentSchema::TABLE_NAME, 'date_label'));
        $I->assertTrue($dbLayer->tableExists(ContentTagSchema::TABLE_NAME));
        $I->assertTrue($dbLayer->indexExists(ContentTagSchema::TABLE_NAME, 'content_tag_idx'));
        $I->assertTrue($dbLayer->indexExists(ContentTagSchema::TABLE_NAME, 'tag_content_idx'));
        $I->assertTrue($dbLayer->tableExists('register_visitor'));
        $I->assertTrue($dbLayer->indexExists('register_visitor', 'last_seen_idx'));
        $I->assertTrue($dbLayer->tableExists('register_visitor_fingerprint'));
        $I->assertTrue($dbLayer->foreignKeyExists('register_visitor_fingerprint', 'fk_visitor'));
        $I->assertTrue($dbLayer->tableExists('register_reaction'));
        $I->assertTrue($dbLayer->indexExists('register_reaction', 'content_reaction_idx'));
        $I->assertTrue($dbLayer->foreignKeyExists('register_reaction', 'fk_content'));
        $I->assertTrue($dbLayer->foreignKeyExists('register_reaction', 'fk_visitor'));
        $I->assertTrue($dbLayer->tableExists(\Register\Module\LinkHealth\Manifest::TARGET_TABLE));
        $I->assertTrue($dbLayer->fieldExists(\Register\Module\LinkHealth\Manifest::TARGET_TABLE, 'url_hash'));
        $I->assertTrue($dbLayer->tableExists(\Register\Module\LinkHealth\Manifest::CONTENT_LINK_TABLE));
        $I->assertTrue($dbLayer->foreignKeyExists(\Register\Module\LinkHealth\Manifest::CONTENT_LINK_TABLE, 'fk_source_content'));
        $I->assertTrue($dbLayer->tableExists(\Register\Module\LinkHealth\Manifest::THROTTLE_TABLE));
    }

    public function staleProductSchemaIsRejectedInsteadOfMigrated(\IntegrationTester $I): void
    {
        /** @var SchemaManager $schemaManager */
        $schemaManager = $I->grabAdminService(SchemaManager::class);
        $I->setConfigValue(SchemaManager::CONFIG_KEY, (string)(SchemaManager::CURRENT_GENERATION + 1));

        $I->expectThrowable(\LogicException::class, $schemaManager->ensureCurrent(...));
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
