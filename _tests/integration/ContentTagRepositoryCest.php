<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
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
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'parent_id'    => 'NULL',
            'title'        => ':title',
            'excerpt'      => "''",
            'body'         => "'<p>Page text</p>'",
            'created_at'   => ':time',
            'published_at' => ':time',
            'updated_at'   => ':time',
            'published'    => '1',
            'slug'         => ':url',
            'template'     => "'mainpage.php'",
            'author_id'    => 'NULL',
        ])->execute([
            'content_type' => ContentType::PAGE->value,
            'title'        => 'Page ' . $suffix,
            'time'         => $timestamp,
            'url'          => 'page-' . $suffix,
        ]);
        $pageId = (int)$dbLayer->insertId();

        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type'     => ':content_type',
            'created_at'       => ':time',
            'published_at'     => ':time',
            'updated_at'       => ':time',
            'revision'         => '1',
            'title'            => ':title',
            'excerpt'          => "''",
            'body'             => "'<p>Post text</p>'",
            'published'        => '1',
            'featured'         => '0',
            'comments_enabled' => '1',
            'series'           => "''",
            'slug'             => ':url',
            'author_id'        => 'NULL',
        ])->execute([
            'content_type' => ContentType::POST->value,
            'title'        => 'Post ' . $suffix,
            'time'         => $timestamp,
            'url'          => 'post-' . $suffix,
        ]);

        return [$pageId, (int)$dbLayer->insertId()];
    }
}
