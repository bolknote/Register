<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Ai\AiSettings;
use Register\Comment\CommentSchema;
use Register\Content\ContentSchema;
use Register\Content\ContentMediaSchema;
use Register\Content\ContentTagSchema;
use Register\Module\BaseModuleRegistry;
use Register\Schema\SchemaManager;
use Register\Url\ContentUrlAliasSchema;
use Register\Core\Extensions\ExtensionManager;
use Register\Core\Extensions\ManifestInterface;
use Register\Core\Model\ExtensionCache;
use Register\Core\Model\UserpicSchema;
use Register\Core\Pdo\DbLayer;

final class ModuleManagerCest
{
    /** @var list<string> */
    private const array OBSOLETE_PRODUCT_TABLES = [
        'articles',
        'art_comments',
        'article_tag',
        'register_blog_posts',
        'register_blog_comments',
        'register_blog_post_tag',
    ];

    public function baseModulesHaveNoOptionalLifecycle(\IntegrationTester $I): void
    {
        /** @var ExtensionManager $manager */
        $manager  = $I->grabAdminService(ExtensionManager::class);
        $registry = new BaseModuleRegistry();
        $list     = $manager->getExtensionList();

        $I->assertSame($registry->ids(), array_column($list['baseModules'], 'id'));
        $I->assertSame(['activitypub'], array_column($list['availableExtensions'], 'entry'));
        $I->assertSame(1, $list['extensionNum']);
        $I->assertSame([], $list['failedExtensions']);
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
        $I->assertTrue($dbLayer->tableExists(ContentMediaSchema::FILE_TABLE));
        $I->assertTrue($dbLayer->fieldExists(ContentMediaSchema::FILE_TABLE, 'usage_count'));
        $I->assertTrue($dbLayer->indexExists(ContentMediaSchema::FILE_TABLE, 'storage_path_idx'));
        $I->assertTrue($dbLayer->tableExists(ContentMediaSchema::USAGE_TABLE));
        $I->assertTrue($dbLayer->foreignKeyExists(ContentMediaSchema::USAGE_TABLE, 'fk_post'));
        $I->assertTrue($dbLayer->foreignKeyExists(ContentMediaSchema::USAGE_TABLE, 'fk_media'));
        $I->assertTrue($dbLayer->tableExists(ContentUrlAliasSchema::TABLE_NAME));
        $I->assertTrue($dbLayer->indexExists(ContentUrlAliasSchema::TABLE_NAME, 'content_idx'));
        $I->assertTrue($dbLayer->foreignKeyExists(
            ContentUrlAliasSchema::TABLE_NAME,
            'fk_content_url_alias_content',
        ));
        $I->assertTrue($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'parent_id'));
        $I->assertTrue($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'modify_time'));
        $I->assertTrue($dbLayer->indexExists(CommentSchema::TABLE_NAME, 'thread_idx'));
        $I->assertTrue($dbLayer->tableExists(UserpicSchema::TABLE_NAME));
        $I->assertTrue($dbLayer->tableExists(UserpicSchema::USER_LINK_TABLE_NAME));
        $I->assertTrue($dbLayer->indexExists(UserpicSchema::USER_LINK_TABLE_NAME, 'userpic_idx'));
        $I->assertTrue($dbLayer->foreignKeyExists(UserpicSchema::USER_LINK_TABLE_NAME, 'fk_user'));
        $I->assertTrue($dbLayer->foreignKeyExists(UserpicSchema::USER_LINK_TABLE_NAME, 'fk_userpic'));
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
        $I->assertTrue($dbLayer->tableExists(\Register\Module\Reactions\ReactionAggregateSchema::TABLE_NAME));
        $I->assertTrue($dbLayer->indexExists(
            \Register\Module\Reactions\ReactionAggregateSchema::TABLE_NAME,
            'target_idx',
        ));
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

    public function generationFifteenGetsTheAdditiveMediaRegistryUpgrade(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        /** @var SchemaManager $schemaManager */
        $schemaManager = $I->grabAdminService(SchemaManager::class);

        ContentMediaSchema::drop($dbLayer);
        $I->setConfigValue(SchemaManager::CONFIG_KEY, '15');

        $I->assertTrue($schemaManager->ensureCurrent());
        $I->assertSame(SchemaManager::CURRENT_GENERATION, $schemaManager->currentGeneration());
        $I->assertTrue($dbLayer->tableExists(ContentMediaSchema::FILE_TABLE));
        $I->assertTrue($dbLayer->tableExists(ContentMediaSchema::USAGE_TABLE));
    }

    public function releaseMigrationPreservesExistingSettings(\IntegrationTester $I): void
    {
        /** @var SchemaManager $schemaManager */
        $schemaManager = $I->grabAdminService(SchemaManager::class);
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        ContentMediaSchema::drop($dbLayer);
        $I->setConfigValue(SchemaManager::CONFIG_KEY, '15');
        $I->setConfigValue(AiSettings::AUTO_ALT_CONFIG_KEY, '0');
        $dbLayer
            ->insert('config')
            ->setValue('name', ':name')->setParameter('name', 'REGISTER_TEST_PRESERVED_SETTING')
            ->setValue('value', ':value')->setParameter('value', 'custom value')
            ->execute();
        $expectedSettings = $dbLayer
            ->select('name, value')
            ->from('config')
            ->orderBy('name')
            ->execute()
            ->fetchAssocAll();
        foreach ($expectedSettings as &$setting) {
            if (($setting['name'] ?? null) === SchemaManager::CONFIG_KEY) {
                $setting['value'] = (string)SchemaManager::CURRENT_GENERATION;
            }
        }

        unset($setting);

        $schemaManager->migrateTo(SchemaManager::CURRENT_GENERATION);

        $I->assertSame(SchemaManager::CURRENT_GENERATION, $schemaManager->currentGeneration());
        $I->assertTrue($dbLayer->tableExists(ContentMediaSchema::FILE_TABLE));
        $I->assertTrue($dbLayer->tableExists(ContentMediaSchema::USAGE_TABLE));

        $autoAlt = $dbLayer
            ->select('value')
            ->from('config')
            ->where('name = :name')->setParameter('name', AiSettings::AUTO_ALT_CONFIG_KEY)
            ->execute()
            ->result();
        $customSetting = $dbLayer
            ->select('value')
            ->from('config')
            ->where('name = :name')->setParameter('name', 'REGISTER_TEST_PRESERVED_SETTING')
            ->execute()
            ->result();
        $I->assertSame('0', $autoAlt);
        $I->assertSame('custom value', $customSetting);
        $I->assertSame($expectedSettings, $dbLayer
            ->select('name, value')
            ->from('config')
            ->orderBy('name')
            ->execute()
            ->fetchAssocAll());
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
