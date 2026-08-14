<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Psr\Log\NullLogger;
use Register\Content\ContentId;
use Register\Content\ContentPublicationQueueHandler;
use Register\Content\ContentPublicationScheduler;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Module\Search\Service\ContentIndexer;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Queue\QueueConsumer;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueHandlerRegistry;
use S2\Cms\Queue\QueuePublisher;

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

        $I->assertTrue($scheduler->hasDue($now));
        $I->assertSame(2, $scheduler->publishDue($now));
        $this->assertPublished($I, $dbLayer, $postId, $now - 60);
        $this->assertPublished($I, $dbLayer, $pageId, $now);
        $this->assertDraft($I, $dbLayer, $futureId, $now + 60);
        $this->assertQueued($I, $dbLayer, ContentId::post($postId));
        $this->assertQueued($I, $dbLayer, ContentId::page($pageId));

        $I->assertFalse($scheduler->hasDue($now));
        $I->assertTrue($scheduler->hasDue($now + 60));
        $I->assertSame(0, $scheduler->publishDue($now));

        $data = ['published' => true, 'scheduled_at' => $now + 120];
        $scheduler->prepareForSave($data);
        $I->assertNull($data['scheduled_at']);
    }

    public function queueHandlerContinuesLargePublicationSetsInBoundedBatches(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var \PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        /** @var ContentPublicationQueueHandler $handler */
        $handler = $I->grabService(ContentPublicationQueueHandler::class);

        $now = time();
        for ($number = 1; $number <= ContentPublicationQueueHandler::BATCH_SIZE + 1; ++$number) {
            $this->insertScheduledContent($dbLayer, ContentType::POST, 'batch-' . $number, $now - 1);
        }

        $publisher = new QueuePublisher($pdo, '');
        $publisher->publish(
            ContentPublicationQueueHandler::JOB_ID,
            ContentPublicationQueueHandler::CODE,
        );
        $consumer = new QueueConsumer(
            $pdo,
            '',
            new NullLogger(),
            new QueueHandlerRegistry($handler),
        );

        $runAt = time();
        $I->assertTrue($consumer->runQueue($runAt, new QueueExecutionBudget(5.0)));
        $I->assertSame(ContentPublicationQueueHandler::BATCH_SIZE, $this->publishedCount($dbLayer));

        $continuation = $this->publicationJob($pdo);
        $I->assertSame(2, (int)$continuation['generation']);

        $pdo->exec("DELETE FROM queue WHERE code = '" . ContentIndexer::QUEUE_CODE . "'");
        $I->assertTrue($consumer->runQueue($runAt + 2, new QueueExecutionBudget(5.0)));
        $I->assertSame(ContentPublicationQueueHandler::BATCH_SIZE + 1, $this->publishedCount($dbLayer));
        $I->assertFalse($this->findPublicationJob($pdo));
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

    private function publishedCount(DbLayer $dbLayer): int
    {
        return (int)$dbLayer
            ->select('COUNT(*)')
            ->from(ContentSchema::TABLE_NAME)
            ->where('slug LIKE :prefix')->setParameter('prefix', 'batch-%')
            ->andWhere('published = 1')
            ->execute()
            ->result()
        ;
    }

    /** @return array<string, mixed> */
    private function publicationJob(\PDO $pdo): array
    {
        $job = $this->findPublicationJob($pdo);
        if (!\is_array($job)) {
            throw new \RuntimeException('The scheduled-publication continuation is missing.');
        }

        return $job;
    }

    /** @return array<string, mixed>|false */
    private function findPublicationJob(\PDO $pdo): array|false
    {
        $statement = $pdo->prepare('SELECT * FROM queue WHERE id = :id AND code = :code');
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the scheduled-publication queue query.');
        }

        $statement->execute([
            'id'   => ContentPublicationQueueHandler::JOB_ID,
            'code' => ContentPublicationQueueHandler::CODE,
        ]);
        return $statement->fetch(\PDO::FETCH_ASSOC);
    }
}
