<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentId;
use Register\Content\ContentTagSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Schema\SchemaMigrator;
use S2\Cms\Pdo\DbLayer;

final class ContentTagRepositoryCest
{
    public function storesRelationsByCanonicalContentIdentity(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var TagRepository $repository */
        $repository = $I->grabService(TagRepository::class);

        [$pageId, $postId] = $this->createPublishedContent($dbLayer, 'repository');
        $firstTagId  = $this->createTag($dbLayer, 'Architecture', 'architecture');
        $secondTagId = $this->createTag($dbLayer, 'Register', 'register-engine');

        $pageIdObject = ContentId::page($pageId);
        $postIdObject = ContentId::post($postId);
        $repository->replace($pageIdObject, [$secondTagId, $firstTagId, $secondTagId]);
        $repository->replace($postIdObject, [$secondTagId]);

        $tagsByContent = $repository->findForContent([$pageIdObject, $postIdObject]);
        $I->assertSame(
            ['Register', 'Architecture'],
            array_column($tagsByContent[(string)$pageIdObject], 'name'),
        );
        $I->assertSame(['Register'], array_column($tagsByContent[(string)$postIdObject], 'name'));
        $I->assertSame($secondTagId, $repository->findBySlug('register-engine')?->id);

        $usages = $repository->findPublishedUsage();
        $counts = [];
        foreach ($usages as $usage) {
            $counts[$usage->tag->slug] = $usage->publishedContentCount;
        }

        $I->assertSame(1, $counts['architecture']);
        $I->assertSame(2, $counts['register-engine']);
        $I->assertSame(
            [(string)$postIdObject],
            array_map(strval(...), $repository->findPublishedContentIds($secondTagId, ContentType::POST)),
        );

        $repository->replace($pageIdObject, []);
        $I->assertSame([], $repository->findForContent([$pageIdObject])[(string)$pageIdObject]);
    }

    public function migrationCopiesLegacyRelationsWithoutRemovingThem(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        /** @var SchemaMigrator $schemaMigrator */
        $schemaMigrator = $I->grabAdminService(SchemaMigrator::class);

        [$pageId, $postId] = $this->createPublishedContent($dbLayer, 'migration');
        $tagId             = $this->createTag($dbLayer, 'Migration', 'migration');

        $dbLayer->insert('article_tag')->values([
            'article_id' => ':content_id',
            'tag_id'     => ':tag_id',
        ])->execute(['content_id' => $pageId, 'tag_id' => $tagId]);
        $dbLayer->insert('s2_blog_post_tag')->values([
            'post_id' => ':content_id',
            'tag_id'  => ':tag_id',
        ])->execute(['content_id' => $postId, 'tag_id' => $tagId]);
        $I->setConfigValue(SchemaMigrator::CONFIG_KEY, '8');

        $I->assertTrue($schemaMigrator->migrate());
        $I->assertSame(2, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(ContentTagSchema::TABLE_NAME)
            ->where('tag_id = :tag_id')->setParameter('tag_id', $tagId)
            ->execute()
            ->result());
        $I->assertSame(1, (int)$dbLayer
            ->select('COUNT(*)')->from('article_tag')
            ->where('tag_id = :tag_id')->setParameter('tag_id', $tagId)
            ->execute()->result());
        $I->assertSame(1, (int)$dbLayer
            ->select('COUNT(*)')->from('s2_blog_post_tag')
            ->where('tag_id = :tag_id')->setParameter('tag_id', $tagId)
            ->execute()->result());
    }

    private function createTag(DbLayer $dbLayer, string $name, string $slug): int
    {
        $dbLayer->insert('tags')->values([
            'name'        => ':name',
            'description' => "''",
            'modify_time' => ':modify_time',
            'url'         => ':url',
        ])->execute([
            'name'        => $name,
            'modify_time' => time(),
            'url'         => $slug,
        ]);

        return (int)$dbLayer->insertId();
    }

    /** @return array{int, int} */
    private function createPublishedContent(DbLayer $dbLayer, string $suffix): array
    {
        $timestamp = time();
        $dbLayer->insert('articles')->values([
            'parent_id'   => '0',
            'title'       => ':title',
            'excerpt'     => "''",
            'pagetext'    => "'<p>Page text</p>'",
            'create_time' => ':time',
            'modify_time' => ':time',
            'published'   => '1',
            'url'         => ':url',
            'template'    => "'mainpage.php'",
            'user_id'     => 'NULL',
        ])->execute([
            'title' => 'Page ' . $suffix,
            'time'  => $timestamp,
            'url'   => 'page-' . $suffix,
        ]);
        $pageId = (int)$dbLayer->insertId();

        $dbLayer->insert('s2_blog_posts')->values([
            'create_time' => ':time',
            'modify_time' => ':time',
            'revision'    => '1',
            'title'       => ':title',
            'text'        => "'<p>Post text</p>'",
            'published'   => '1',
            'favorite'    => '0',
            'commented'   => '1',
            'label'       => "''",
            'url'         => ':url',
            'user_id'     => 'NULL',
        ])->execute([
            'title' => 'Post ' . $suffix,
            'time'  => $timestamp,
            'url'   => 'post-' . $suffix,
        ]);

        return [$pageId, (int)$dbLayer->insertId()];
    }
}
