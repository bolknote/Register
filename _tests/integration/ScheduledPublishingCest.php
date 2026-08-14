<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentId;
use Register\Content\ContentPublicationScheduler;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Module\Search\Service\ContentIndexer;
use S2\Cms\Pdo\DbLayer;

final class ScheduledPublishingCest
{
    public function publishesOnlyDueDraftsAndQueuesLifecycleUpdates(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var ContentPublicationScheduler $scheduler */
        $scheduler = $I->grabService(ContentPublicationScheduler::class);

        $now      = 1_800_000_000;
        $postId   = $this->insertScheduledContent($dbLayer, ContentType::POST, 'scheduled-post', $now - 60);
        $pageId   = $this->insertScheduledContent($dbLayer, ContentType::PAGE, 'scheduled-page', $now);
        $futureId = $this->insertScheduledContent($dbLayer, ContentType::POST, 'future-post', $now + 60);

        $I->assertSame(2, $scheduler->publishDue($now));
        $this->assertPublished($I, $dbLayer, $postId, $now - 60);
        $this->assertPublished($I, $dbLayer, $pageId, $now);
        $this->assertDraft($I, $dbLayer, $futureId, $now + 60);
        $this->assertQueued($I, $dbLayer, ContentId::post($postId));
        $this->assertQueued($I, $dbLayer, ContentId::page($pageId));

        $I->assertSame(0, $scheduler->publishDue($now));

        $data = ['published' => true, 'scheduled_at' => $now + 120];
        $scheduler->prepareForSave($data);
        $I->assertNull($data['scheduled_at']);
    }

    private function insertScheduledContent(
        DbLayer $dbLayer,
        ContentType $contentType,
        string $slug,
        int $scheduledAt,
    ): int {
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'slug_scope'   => "'root'",
            'slug'         => ':slug',
            'title'        => ':title',
            'excerpt'      => "''",
            'body'         => "'<p>Scheduled content</p>'",
            'created_at'   => ':created_at',
            'published_at' => 'NULL',
            'scheduled_at' => ':scheduled_at',
            'updated_at'   => ':updated_at',
            'published'    => '0',
        ])->execute([
            'content_type' => $contentType->value,
            'slug'         => $slug,
            'title'        => $slug,
            'created_at'   => $scheduledAt - 300,
            'scheduled_at' => $scheduledAt,
            'updated_at'   => $scheduledAt - 300,
        ]);

        return (int)$dbLayer->insertId();
    }

    private function assertPublished(\IntegrationTester $I, DbLayer $dbLayer, int $id, int $publishedAt): void
    {
        $row = $this->contentState($dbLayer, $id);
        $I->assertSame(1, (int)$row['published']);
        $I->assertSame($publishedAt, (int)$row['published_at']);
        $I->assertSame(0, (int)$row['scheduled_at']);
    }

    private function assertDraft(\IntegrationTester $I, DbLayer $dbLayer, int $id, int $scheduledAt): void
    {
        $row = $this->contentState($dbLayer, $id);
        $I->assertSame(0, (int)$row['published']);
        $I->assertNull($row['published_at']);
        $I->assertSame($scheduledAt, (int)$row['scheduled_at']);
    }

    /** @return array<string, mixed> */
    private function contentState(DbLayer $dbLayer, int $id): array
    {
        $row = $dbLayer
            ->select('published, published_at, scheduled_at')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $id)
            ->execute()
            ->fetchAssoc()
        ;

        if ($row === false) {
            throw new \RuntimeException('Scheduled content fixture disappeared.');
        }

        return $row;
    }

    private function assertQueued(\IntegrationTester $I, DbLayer $dbLayer, ContentId $contentId): void
    {
        $count = $dbLayer
            ->select('COUNT(*)')
            ->from('queue')
            ->where('id = :id')->setParameter('id', (string)$contentId)
            ->andWhere('code = :code')->setParameter('code', ContentIndexer::QUEUE_CODE)
            ->execute()
            ->result()
        ;

        $I->assertSame(1, (int)$count);
    }
}
