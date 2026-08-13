<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentSchema;
use Register\Content\ContentSchema;
use Register\Content\ContentStatisticsRepository;
use Register\Content\ContentType;
use S2\Cms\Pdo\DbLayer;

final class ContentStatisticsRepositoryCest
{
    public function countsOnlyPublishedPostsAndTheirVisibleComments(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var ContentStatisticsRepository $repository */
        $repository = $I->grabService(ContentStatisticsRepository::class);
        $before     = $repository->published(ContentType::POST);

        $publishedId   = $this->insertPost($dbLayer, true, 'published-statistics-post');
        $unpublishedId = $this->insertPost($dbLayer, false, 'unpublished-statistics-post');
        $this->insertComment($dbLayer, $publishedId, true);
        $this->insertComment($dbLayer, $publishedId, false);
        $this->insertComment($dbLayer, $unpublishedId, true);

        $after = $repository->published(ContentType::POST);
        $I->assertSame($before->contentCount + 1, $after->contentCount);
        $I->assertSame($before->commentCount + 1, $after->commentCount);
    }

    private function insertPost(DbLayer $dbLayer, bool $published, string $slug): int
    {
        $time = time();
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'slug'         => ':slug',
            'title'        => ':title',
            'excerpt'      => "''",
            'body'         => "'<p>Statistics</p>'",
            'created_at'   => ':time',
            'published_at' => $published ? ':time' : 'NULL',
            'updated_at'   => ':time',
            'published'    => ':published',
        ])->execute([
            'content_type' => ContentType::POST->value,
            'slug'         => $slug,
            'title'        => $slug,
            'time'         => $time,
            'published'    => (int)$published,
        ]);

        return (int)$dbLayer->insertId();
    }

    private function insertComment(DbLayer $dbLayer, int $contentId, bool $shown): void
    {
        $dbLayer->insert(CommentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'content_id'   => ':content_id',
            'time'         => ':time',
            'ip'           => "'127.0.0.1'",
            'nick'         => "'Reader'",
            'email'        => "'reader@example.test'",
            'shown'        => ':shown',
            'text'         => "'Comment'",
        ])->execute([
            'content_type' => ContentType::POST->value,
            'content_id'   => $contentId,
            'time'         => time(),
            'shown'        => (int)$shown,
        ]);
    }
}
