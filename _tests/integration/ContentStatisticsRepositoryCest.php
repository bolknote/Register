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

    public function separatesDraftScheduledAndOverduePosts(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var ContentStatisticsRepository $repository */
        $repository = $I->grabService(ContentStatisticsRepository::class);
        $now        = 2_000_000_000;
        $before     = $repository->editorial(ContentType::POST, $now);

        $this->insertPost($dbLayer, false, 'editorial-draft', 0);
        $this->insertPost($dbLayer, false, 'editorial-scheduled', $now + 3_600);
        $this->insertPost($dbLayer, false, 'editorial-overdue', $now - 3_600);
        $this->insertPost($dbLayer, true, 'editorial-published', $now + 7_200);

        $after = $repository->editorial(ContentType::POST, $now);
        $I->assertSame($before->draftCount + 1, $after->draftCount);
        $I->assertSame($before->scheduledCount + 1, $after->scheduledCount);
        $I->assertSame($before->overdueCount + 1, $after->overdueCount);
        $I->assertSame(
            min($before->nextScheduledAt ?? PHP_INT_MAX, $now + 3_600),
            $after->nextScheduledAt,
        );
    }

    private function insertPost(DbLayer $dbLayer, bool $published, string $slug, ?int $scheduledAt = null): int
    {
        $time = time();
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'slug_scope'   => "'root'",
            'slug'         => ':slug',
            'title'        => ':title',
            'excerpt'      => "''",
            'body'         => "'<p>Statistics</p>'",
            'created_at'   => ':time',
            'published_at' => $published ? ':time' : 'NULL',
            'scheduled_at' => ':scheduled_at',
            'updated_at'   => ':time',
            'published'    => ':published',
        ])->execute([
            'content_type' => ContentType::POST->value,
            'slug'         => $slug,
            'title'        => $slug,
            'time'         => $time,
            'published'    => (int)$published,
            'scheduled_at' => $scheduledAt ?? 0,
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
