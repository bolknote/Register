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
use Register\Content\ContentPublicationScheduler;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Module\LinkHealth\ArchiveLookupResult;
use Register\Module\LinkHealth\HostRequestThrottle;
use Register\Module\LinkHealth\LinkArchiveQueueHandler;
use Register\Module\LinkHealth\LinkCheckQueueHandler;
use Register\Module\LinkHealth\LinkHealthPolicy;
use Register\Module\LinkHealth\LinkHealthRepository;
use Register\Module\LinkHealth\LinkHealthResultRecorder;
use Register\Module\LinkHealth\LinkHealthTransaction;
use Register\Module\LinkHealth\LinkProbeInterface;
use Register\Module\LinkHealth\LinkProbeMethod;
use Register\Module\LinkHealth\LinkProbeResult;
use Register\Module\LinkHealth\LinkProbeState;
use Register\Module\LinkHealth\LinkProbeStep;
use Register\Module\LinkHealth\WaybackClientInterface;
use Register\Module\LinkHealth\WaybackRequestThrottle;
use Register\Module\LinkHealth\LinkInventory;
use Register\Module\LinkHealth\LinkInventoryQueueHandler;
use Register\Module\LinkHealth\LinkInventoryRepository;
use Register\Module\LinkHealth\LinkKind;
use Register\Module\LinkHealth\LinkQueue;
use Register\Module\LinkHealth\LinkRepairQueueHandler;
use Register\Module\LinkHealth\Manifest;
use Register\Module\LinkHealth\Admin\LocalLinkDeletionGuard;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\BackgroundWorkRunner;
use Register\Core\Queue\QueueConsumer;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerRegistry;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Queue\QueueRunnerLease;
use Register\Core\Queue\QueueSchema;
use Register\Core\Queue\ScheduledMaintenance;

final class LinkHealthCest
{
    public function inventoriesEveryUsageButQueuesEachExternalTargetOnce(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer   = $I->grabService(DbLayer::class);
        $targetId  = $this->insertPost($dbLayer, 'local-target', '<p>Target</p>');
        $firstId   = $this->insertPost($dbLayer, 'first-source', <<<'HTML'
            <p>
                <a href="https://outside.example/path?x=1#first">Outside one</a>
                <a href="https://outside.example/path?x=1#second">Outside two</a>
                <a href="/local-target#part">Local</a>
                <a href="https://web.archive.org/web/20200101000000/https://old.example/">Archived</a>
            </p>
            HTML);
        $secondId = $this->insertPost(
            $dbLayer,
            'second-source',
            '<p><a href="https://outside.example/path?x=1">Same outside target</a></p>',
        );

        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($firstId), 1_800_000_000);
        $inventory->synchronize(ContentId::post($secondId), 1_800_000_001);

        $targets = $dbLayer->select('id, normalized_url, kind, local_content_id')
            ->from(Manifest::TARGET_TABLE)
            ->orderBy('normalized_url')
            ->execute()
            ->fetchAssocAll()
        ;
        $I->assertCount(3, $targets);

        $external = $this->findTarget($targets, 'https://outside.example/path?x=1');
        $local    = $this->findTarget($targets, '/local-target');
        $archive  = $this->findTarget(
            $targets,
            'https://web.archive.org/web/20200101000000/https://old.example/',
        );
        $I->assertSame(LinkKind::EXTERNAL->value, $external['kind']);
        $I->assertSame(LinkKind::LOCAL->value, $local['kind']);
        $I->assertSame($targetId, (int)$local['local_content_id']);
        $I->assertSame(LinkKind::ARCHIVE->value, $archive['kind']);

        $I->assertSame(
            2,
            (int)$dbLayer->select('occurrence_count')
                ->from(Manifest::CONTENT_LINK_TABLE)
                ->where('source_content_id = :source')->setParameter('source', $firstId)
                ->andWhere('target_id = :target')->setParameter('target', (int)$external['id'])
                ->execute()
                ->result(),
        );
        $I->assertSame(
            2,
            (int)$dbLayer->select('COUNT(*)')
                ->from(Manifest::CONTENT_LINK_TABLE)
                ->where('target_id = :target')->setParameter('target', (int)$external['id'])
                ->execute()
                ->result(),
        );
        $I->assertSame(
            1,
            (int)$dbLayer->select('COUNT(*)')
                ->from('queue')
                ->where('code = :code')->setParameter('code', LinkQueue::CHECK_CODE)
                ->execute()
                ->result(),
        );
    }

    public function doesNotScheduleTargetsWithoutCurrentUsages(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId  = $this->insertPost(
            $dbLayer,
            'removed-external-link',
            '<a href="https://outside.example/removed">Temporary link</a>',
        );

        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($postId), 1_800_000_000);

        $targetId = (int)$dbLayer->select('id')->from(Manifest::TARGET_TABLE)
            ->where('normalized_url = :url')->setParameter('url', 'https://outside.example/removed')
            ->execute()->result();

        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('body', "''")
            ->set('revision', 'revision + 1')
            ->where('id = :id')->setParameter('id', $postId)
            ->execute();
        $inventory->synchronize(ContentId::post($postId), 1_800_000_001);

        /** @var LinkInventoryRepository $inventoryRepository */
        $inventoryRepository = $I->grabService(LinkInventoryRepository::class);
        /** @var LinkHealthRepository $healthRepository */
        $healthRepository = $I->grabService(LinkHealthRepository::class);
        $I->assertSame([], $inventoryRepository->dueTargetIds(2_000_000_000, 50));
        $I->assertSame([], $inventoryRepository->repairableTargetIds(50));
        $I->assertFalse($healthRepository->hasUsages($targetId));
        $I->assertSame(
            0,
            (int)$dbLayer->select('COUNT(*)')->from(Manifest::CONTENT_LINK_TABLE)
                ->where('target_id = :target_id')->setParameter('target_id', $targetId)
                ->execute()->result(),
        );
    }

    public function advancesHttpProbeAcrossFiveSecondShutdownSlices(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId = $this->insertPost(
            $dbLayer,
            'resumable-check-source',
            '<a href="https://outside.example/resumable">External</a>',
        );

        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($postId), 1_800_000_000);

        $url      = 'https://outside.example/resumable';
        $targetId = $this->targetId($dbLayer, $url);
        $probe    = new SequencedLinkProbe([
            LinkProbeStep::pending(new LinkProbeState($url, LinkProbeMethod::GET)),
            LinkProbeStep::complete(new LinkProbeResult($url, 200)),
        ]);
        /** @var LinkHealthRepository $healthRepository */
        $healthRepository = $I->grabService(LinkHealthRepository::class);
        /** @var LinkHealthPolicy $policy */
        $policy = $I->grabService(LinkHealthPolicy::class);
        $queuePdo = $this->shutdownQueue();
        $queuePublisher = new QueuePublisher($queuePdo, '');
        $queuePublisher->publish(
            LinkQueue::targetJobId($targetId),
            LinkQueue::CHECK_CODE,
            LinkQueue::checkPayload($targetId),
            0,
        );
        $now     = time() + 60;
        $clock   = $now;
        /** @var HostRequestThrottle $hostRequestThrottle */
        $hostRequestThrottle = $I->grabService(HostRequestThrottle::class);
        /** @var LinkHealthResultRecorder $resultRecorder */
        $resultRecorder = $I->grabService(LinkHealthResultRecorder::class);
        $handler = new LinkCheckQueueHandler(
            $healthRepository,
            $policy,
            $probe,
            $hostRequestThrottle,
            $resultRecorder,
            $queuePublisher,
            static function () use (&$clock): int {
                return $clock;
            },
        );
        $consumer = new QueueConsumer(
            $queuePdo,
            '',
            new NullLogger(),
            new QueueHandlerRegistry($handler),
        );
        /** @var ContentPublicationScheduler $publicationScheduler */
        $publicationScheduler = $I->grabService(ContentPublicationScheduler::class);
        $maintenance = new ScheduledMaintenance(
            $queuePdo,
            '',
            $queuePublisher,
            $publicationScheduler,
            false,
        );
        $runner = new BackgroundWorkRunner(
            $queuePdo,
            new QueueRunnerLease($queuePdo, ''),
            $consumer,
            $maintenance,
            new NullLogger(),
        );

        $I->assertLessThanOrEqual(5.0, $handler->minimumExecutionTime());
        $I->assertSame(1, $runner->run(5.0, 1));
        $I->assertCount(1, $probe->states);
        $I->assertSame(LinkProbeMethod::HEAD, $probe->states[0]->method);

        $continuedJob = $this->queueJob($queuePdo, $targetId);
        $I->assertIsArray($continuedJob);
        $I->assertSame(2, (int)$continuedJob['generation']);
        $I->assertSame($now + 1, (int)$continuedJob['available_at']);

        $continuedPayload = json_decode((string)$continuedJob['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($continuedPayload)) {
            throw new \RuntimeException('The continued link-check payload is invalid.');
        }

        $I->assertSame('GET', $continuedPayload['probe']['method']);
        $I->assertTrue(LinkQueue::isOperationToken($continuedPayload['token'] ?? null));
        $I->assertSame(
            0,
            (int)$dbLayer->select('COUNT(*)')->from(Manifest::CHECK_TABLE)->execute()->result(),
        );
        $I->assertSame(0, $runner->run(5.0, 1));

        $clock = $now + HostRequestThrottle::INTERVAL_SECONDS;
        $queuePdo->exec('UPDATE queue SET available_at = 0');
        $I->assertSame(1, $runner->run(5.0, 1));
        $I->assertCount(2, $probe->states);
        $I->assertSame(LinkProbeMethod::GET, $probe->states[1]->method);
        $I->assertSame(
            'healthy',
            (string)$dbLayer->select('health_status')->from(Manifest::TARGET_TABLE)
                ->where('id = :id')->setParameter('id', $targetId)->execute()->result(),
        );
        $I->assertSame(
            1,
            (int)$dbLayer->select('COUNT(*)')->from(Manifest::CHECK_TABLE)->execute()->result(),
        );
        $I->assertSame(
            0,
            $this->queueCount($queuePdo),
        );
    }

    public function recordsProbeAndArchiveFollowUpExactlyOnce(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId  = $this->insertPost(
            $dbLayer,
            'idempotent-link-check',
            '<a href="https://broken.example/idempotent">External</a>',
        );
        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($postId), 1_800_000_000);

        $targetId = $this->targetId($dbLayer, 'https://broken.example/idempotent');
        $dbLayer->update(Manifest::TARGET_TABLE)
            ->set('health_status', "'suspect'")
            ->set('failure_count', '1')
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute();

        /** @var LinkHealthRepository $repository */
        $repository = $I->grabService(LinkHealthRepository::class);
        $target     = $repository->findTarget($targetId);
        $I->assertNotNull($target);
        /** @var LinkHealthPolicy $policy */
        $policy   = $I->grabService(LinkHealthPolicy::class);
        $probe    = new LinkProbeResult($target->url, 404);
        $decision = $policy->decide($target, $probe, 1_800_086_400);
        /** @var LinkHealthResultRecorder $recorder */
        $recorder = $I->grabService(LinkHealthResultRecorder::class);
        $token    = str_repeat('a', 32);

        $recorder->recordProbe($token, $target, $probe, $decision, 1_800_086_400);
        $recorder->recordProbe($token, $target, $probe, $decision, 1_800_086_400);

        $row = $dbLayer->select('health_status, failure_count')->from(Manifest::TARGET_TABLE)
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute()->fetchAssoc();
        $I->assertIsArray($row);
        $I->assertSame('broken', $row['health_status']);
        $I->assertSame(2, (int)$row['failure_count']);
        $I->assertSame(
            1,
            (int)$dbLayer->select('COUNT(*)')->from(Manifest::CHECK_TABLE)
                ->where('probe_token = :token')->setParameter('token', $token)
                ->execute()->result(),
        );
        $archivePayload = $dbLayer->select('payload')->from('queue')
            ->where('id = :id')->setParameter('id', LinkQueue::targetJobId($targetId))
            ->andWhere('code = :code')->setParameter('code', LinkQueue::ARCHIVE_CODE)
            ->execute()->result();
        $I->assertIsString($archivePayload);
        $decodedPayload = json_decode($archivePayload, true, 4, JSON_THROW_ON_ERROR);
        $I->assertIsArray($decodedPayload);
        $I->assertTrue(LinkQueue::isOperationToken($decodedPayload['token'] ?? null));
    }

    public function rollsBackProbeWhenArchiveFollowUpCannotBeQueued(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId  = $this->insertPost(
            $dbLayer,
            'atomic-link-check',
            '<a href="https://broken.example/atomic">External</a>',
        );
        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($postId), 1_800_000_000);

        $targetId = $this->targetId($dbLayer, 'https://broken.example/atomic');
        $dbLayer->update(Manifest::TARGET_TABLE)
            ->set('health_status', "'suspect'")
            ->set('failure_count', '1')
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute();

        /** @var LinkHealthRepository $repository */
        $repository = $I->grabService(LinkHealthRepository::class);
        $target     = $repository->findTarget($targetId);
        $I->assertNotNull($target);
        /** @var LinkHealthPolicy $policy */
        $policy   = $I->grabService(LinkHealthPolicy::class);
        $probe    = new LinkProbeResult($target->url, 404);
        $decision = $policy->decide($target, $probe, 1_800_086_400);
        /** @var \PDO $pdo */
        $pdo   = $I->grabService(\PDO::class);
        $token = str_repeat('b', 32);
        /** @var LinkHealthTransaction $transaction */
        $transaction = $I->grabService(LinkHealthTransaction::class);
        $recorder    = new LinkHealthResultRecorder(
            $repository,
            $transaction,
            new FailingQueuePublisher($pdo, ''),
        );

        try {
            $recorder->recordProbe($token, $target, $probe, $decision, 1_800_086_400);
            $I->fail('The forced queue failure must escape the result transaction.');
        } catch (\PDOException) {
            // Expected: the result transaction must roll back both target state and history.
        }

        $row = $dbLayer->select('health_status, failure_count')->from(Manifest::TARGET_TABLE)
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute()->fetchAssoc();
        $I->assertIsArray($row);
        $I->assertSame('suspect', $row['health_status']);
        $I->assertSame(1, (int)$row['failure_count']);
        $I->assertSame(
            0,
            (int)$dbLayer->select('COUNT(*)')->from(Manifest::CHECK_TABLE)
                ->where('probe_token = :token')->setParameter('token', $token)
                ->execute()->result(),
        );
    }

    public function recordsArchiveLookupAndRepairFollowUpExactlyOnce(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId  = $this->insertPost(
            $dbLayer,
            'idempotent-archive-lookup',
            '<a href="https://broken.example/archive-idempotent">External</a>',
        );
        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($postId), 1_800_000_000);

        $targetId = $this->targetId($dbLayer, 'https://broken.example/archive-idempotent');
        $this->markBroken($dbLayer, $targetId);
        /** @var LinkHealthRepository $repository */
        $repository = $I->grabService(LinkHealthRepository::class);
        $target     = $repository->findTarget($targetId);
        $I->assertNotNull($target);
        /** @var LinkHealthResultRecorder $recorder */
        $recorder = $I->grabService(LinkHealthResultRecorder::class);
        $token    = str_repeat('c', 32);
        $result   = ArchiveLookupResult::available(
            'https://web.archive.org/web/20250102030405/https://broken.example/archive-idempotent',
            '20250102030405',
        );

        $recorder->recordArchiveLookup($token, $target, $result, 1_800_000_000, true);
        $recorder->recordArchiveLookup($token, $target, $result, 1_800_000_000, true);

        $row = $dbLayer->select('archive_status, archive_lookup_token')->from(Manifest::TARGET_TABLE)
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute()->fetchAssoc();
        $I->assertIsArray($row);
        $I->assertSame('available', $row['archive_status']);
        $I->assertSame($token, $row['archive_lookup_token']);
        $I->assertSame(
            1,
            (int)$dbLayer->select('COUNT(*)')->from('queue')
                ->where('id = :id')->setParameter('id', LinkQueue::targetJobId($targetId))
                ->andWhere('code = :code')->setParameter('code', LinkQueue::REPAIR_CODE)
                ->execute()->result(),
        );
    }

    public function backfillsExistingContentInBoundedBatches(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('published', '0')
            ->execute();

        $contentIds = [];
        for ($number = 1; $number <= 21; ++$number) {
            $contentIds[] = $this->insertPost(
                $dbLayer,
                'backfill-' . $number,
                '<a href="https://outside.example/backfill#part-' . $number . '">External</a>',
            );
        }

        $dbLayer->update('config')
            ->set('value', "'0'")
            ->where('name = :name')->setParameter('name', Manifest::INVENTORY_GENERATION_CONFIG_KEY)
            ->execute();

        /** @var LinkInventoryQueueHandler $handler */
        $handler = $I->grabService(LinkInventoryQueueHandler::class);
        $handler->handle(
            LinkInventoryQueueHandler::JOB_ID,
            LinkQueue::INVENTORY_CODE,
            ['cursor' => 0],
            new QueueExecutionBudget(5.0),
        );
        $I->assertSame('0', $this->configValue($dbLayer, Manifest::INVENTORY_GENERATION_CONFIG_KEY));
        $I->assertSame(20, $this->contentLinkCount($dbLayer));

        $handler->handle(
            LinkInventoryQueueHandler::JOB_ID,
            LinkQueue::INVENTORY_CODE,
            ['cursor' => $contentIds[19]],
            new QueueExecutionBudget(5.0),
        );
        $I->assertSame(
            (string)Manifest::INVENTORY_GENERATION,
            $this->configValue($dbLayer, Manifest::INVENTORY_GENERATION_CONFIG_KEY),
        );
        $I->assertSame(21, $this->contentLinkCount($dbLayer));
        $I->assertSame(
            1,
            (int)$dbLayer->select('COUNT(*)')->from(Manifest::TARGET_TABLE)
                ->where('normalized_url = :url')->setParameter('url', 'https://outside.example/backfill')
                ->execute()->result(),
        );
    }

    public function repairsOnlyMatchingHrefValuesAndPreservesTheirFragments(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId  = $this->insertPost($dbLayer, 'repair-source', <<<'HTML'
            <p><a class="first" href="https://broken.example/item#one">One</a></p>
            <a href='https://broken.example/item#two' rel="nofollow">Two</a>
            <script>const example = '<a href="https://broken.example/item#script">';</script>
            HTML);

        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($postId), 1_800_000_000);

        $targetId = (int)$dbLayer->select('id')->from(Manifest::TARGET_TABLE)
            ->where('normalized_url = :url')->setParameter('url', 'https://broken.example/item')
            ->execute()->result();
        $archiveUrl = 'https://web.archive.org/web/20250102030405/https://broken.example/item';
        $this->markBrokenWithArchive($dbLayer, $targetId, $archiveUrl);
        /** @var LinkInventoryRepository $inventoryRepository */
        $inventoryRepository = $I->grabService(LinkInventoryRepository::class);
        $I->assertSame([$targetId], $inventoryRepository->repairableTargetIds(50));

        /** @var LinkRepairQueueHandler $handler */
        $handler = $I->grabService(LinkRepairQueueHandler::class);
        $handler->handle(
            LinkQueue::targetJobId($targetId),
            LinkQueue::REPAIR_CODE,
            ['target_id' => $targetId],
            new QueueExecutionBudget(5.0),
        );

        $row = $dbLayer->select('body, revision')->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()->fetchAssoc();
        $I->assertIsArray($row);
        $I->assertSame(2, (int)$row['revision']);
        $I->assertStringContainsString('href="' . $archiveUrl . '#one"', (string)$row['body']);
        $I->assertStringContainsString("href='" . $archiveUrl . "#two'", (string)$row['body']);
        $I->assertStringContainsString('https://broken.example/item#script', (string)$row['body']);
        $I->assertSame(
            2,
            (int)$dbLayer->select('occurrence_count')->from(Manifest::REPAIR_TABLE)
                ->where('target_id = :target_id')->setParameter('target_id', $targetId)
                ->execute()->result(),
        );
    }

    public function pacesWaybackLookupsThroughDurableQueueDeferral(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $firstContentId = $this->insertPost(
            $dbLayer,
            'first-wayback-source',
            '<a href="https://broken.example/first">First</a>',
        );
        $secondContentId = $this->insertPost(
            $dbLayer,
            'second-wayback-source',
            '<a href="https://broken.example/second">Second</a>',
        );

        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($firstContentId), 1_800_000_000);
        $inventory->synchronize(ContentId::post($secondContentId), 1_800_000_000);

        $firstTargetId  = $this->targetId($dbLayer, 'https://broken.example/first');
        $secondTargetId = $this->targetId($dbLayer, 'https://broken.example/second');
        $this->markBroken($dbLayer, $firstTargetId);
        $this->markBroken($dbLayer, $secondTargetId);
        $dbLayer->update(Manifest::THROTTLE_TABLE)
            ->set('next_request_at', '0')
            ->where('service = :service')->setParameter('service', WaybackRequestThrottle::SERVICE)
            ->execute();

        $now           = 1_800_000_000;
        $waybackClient = new CountingWaybackClient();
        /** @var LinkHealthRepository $healthRepository */
        $healthRepository = $I->grabService(LinkHealthRepository::class);
        /** @var WaybackRequestThrottle $throttle */
        $throttle = $I->grabService(WaybackRequestThrottle::class);
        /** @var QueuePublisher $queuePublisher */
        $queuePublisher = $I->grabService(QueuePublisher::class);
        /** @var DynamicConfigProvider $configProvider */
        $configProvider = $I->grabService(DynamicConfigProvider::class);
        /** @var LinkHealthResultRecorder $resultRecorder */
        $resultRecorder = $I->grabService(LinkHealthResultRecorder::class);
        $handler = new LinkArchiveQueueHandler(
            $healthRepository,
            $waybackClient,
            $throttle,
            $resultRecorder,
            $queuePublisher,
            $configProvider->getBoolProxy(Manifest::AUTO_REPAIR_CONFIG_KEY),
            static fn(): int => $now,
        );

        $I->assertLessThanOrEqual(5.0, $handler->minimumExecutionTime());
        $firstPayload  = LinkQueue::archivePayload($firstTargetId);
        $secondPayload = LinkQueue::archivePayload($secondTargetId);
        $handler->handle(
            LinkQueue::targetJobId($firstTargetId),
            LinkQueue::ARCHIVE_CODE,
            $firstPayload,
            new QueueExecutionBudget(5.0),
        );
        $handler->handle(
            LinkQueue::targetJobId($secondTargetId),
            LinkQueue::ARCHIVE_CODE,
            $secondPayload,
            new QueueExecutionBudget(5.0),
        );

        $I->assertSame(1, $waybackClient->lookups);
        $deferredJob = $dbLayer->select('available_at')
            ->from('queue')
            ->where('id = :id')->setParameter('id', LinkQueue::targetJobId($secondTargetId))
            ->andWhere('code = :code')->setParameter('code', LinkQueue::ARCHIVE_CODE)
            ->execute()->fetchAssoc();
        $I->assertIsArray($deferredJob);
        $I->assertSame($now + WaybackRequestThrottle::INTERVAL_SECONDS, (int)$deferredJob['available_at']);

        $laterHandler = new LinkArchiveQueueHandler(
            $healthRepository,
            $waybackClient,
            $throttle,
            $resultRecorder,
            $queuePublisher,
            $configProvider->getBoolProxy(Manifest::AUTO_REPAIR_CONFIG_KEY),
            static fn(): int => $now + WaybackRequestThrottle::INTERVAL_SECONDS,
        );
        $laterHandler->handle(
            LinkQueue::targetJobId($secondTargetId),
            LinkQueue::ARCHIVE_CODE,
            $secondPayload,
            new QueueExecutionBudget(5.0),
        );
        $I->assertSame(2, $waybackClient->lookups);
    }

    public function refusesToRepairARevisionChangedAfterInventory(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId  = $this->insertPost(
            $dbLayer,
            'stale-repair-source',
            '<a href="https://broken.example/stale">Old editor body</a>',
        );

        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($postId), 1_800_000_000);

        $targetId = (int)$dbLayer->select('id')->from(Manifest::TARGET_TABLE)
            ->where('normalized_url = :url')->setParameter('url', 'https://broken.example/stale')
            ->execute()->result();
        $this->markBrokenWithArchive(
            $dbLayer,
            $targetId,
            'https://web.archive.org/web/20250102030405/https://broken.example/stale',
        );
        $freshBody = '<p>Fresh editor body <a href="https://broken.example/stale">kept</a></p>';
        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('body', ':body')->setParameter('body', $freshBody)
            ->set('revision', 'revision + 1')
            ->where('id = :id')->setParameter('id', $postId)
            ->execute();

        /** @var LinkRepairQueueHandler $handler */
        $handler = $I->grabService(LinkRepairQueueHandler::class);
        $handler->handle(
            LinkQueue::targetJobId($targetId),
            LinkQueue::REPAIR_CODE,
            ['target_id' => $targetId],
            new QueueExecutionBudget(5.0),
        );

        $I->assertSame(
            $freshBody,
            (string)$dbLayer->select('body')->from(ContentSchema::TABLE_NAME)
                ->where('id = :id')->setParameter('id', $postId)->execute()->result(),
        );
        $I->assertSame(
            0,
            (int)$dbLayer->select('COUNT(*)')->from(Manifest::REPAIR_TABLE)->execute()->result(),
        );
        $I->assertSame(
            2,
            (int)$dbLayer->select('content_revision')->from(Manifest::CONTENT_LINK_TABLE)
                ->where('source_content_id = :source_id')->setParameter('source_id', $postId)
                ->andWhere('target_id = :target_id')->setParameter('target_id', $targetId)
                ->execute()->result(),
        );
    }

    public function resolvesLocalTargetsAgainAndBlocksDeletionFromOutsideTheBranch(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer  = $I->grabService(DbLayer::class);
        $targetId = $this->insertPost($dbLayer, 'old-target', '<p>Target</p>');
        $sourceId = $this->insertPost(
            $dbLayer,
            'source-with-future-link',
            '<p><a href="/future-target">Future target path</a></p>',
        );

        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($sourceId), 1_800_000_000);

        $I->assertFalse($dbLayer->select('local_content_id')->from(Manifest::TARGET_TABLE)
            ->where('normalized_url = :url')->setParameter('url', '/future-target')
            ->execute()->result());

        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('slug', ':slug')->setParameter('slug', 'future-target')
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute();
        $dbLayer->update('config')
            ->set('value', "'0'")
            ->where('name = :name')->setParameter('name', Manifest::INVENTORY_GENERATION_CONFIG_KEY)
            ->execute();

        /** @var LocalLinkDeletionGuard $guard */
        $guard = $I->grabAdminService(LocalLinkDeletionGuard::class);
        $inventoryViolations = $guard->violations(ContentId::post($targetId));
        $I->assertCount(1, $inventoryViolations);

        $dbLayer->update('config')
            ->set('value', ':value')->setParameter('value', (string)Manifest::INVENTORY_GENERATION)
            ->where('name = :name')->setParameter('name', Manifest::INVENTORY_GENERATION_CONFIG_KEY)
            ->execute();

        $violations = $guard->violations(ContentId::post($targetId));

        $I->assertCount(1, $violations);
        $I->assertStringContainsString('old-target', $violations[0]);
        $I->assertStringContainsString('source-with-future-link', $violations[0]);
        $I->assertSame([], $guard->violations(ContentId::post($targetId), ContentId::post($sourceId)));
    }

    public function rendersTheAdministrativeLinkOverview(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId  = $this->insertPost(
            $dbLayer,
            'admin-overview-source',
            '<a href="https://outside.example/overview">External</a>',
        );
        /** @var LinkInventory $inventory */
        $inventory = $I->grabService(LinkInventory::class);
        $inventory->synchronize(ContentId::post($postId), 1_800_000_000);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=LinkHealth');
        $I->seeElement('.link-health-admin');
        $I->canSee('https://outside.example/overview');
        $I->seeElement('button[data-operation="recheck"]');

        $csrfToken = (string)$I->grabAttributeFrom('input[name="link_health_csrf_token"]', 'value');
        $targetId = (int)$dbLayer->select('id')->from(Manifest::TARGET_TABLE)
            ->where('normalized_url = :url')->setParameter('url', 'https://outside.example/overview')
            ->execute()->result();
        $I->sendPost('https://localhost/_admin/ajax.php?action=register_link_health_action', [
            'csrf_token' => $csrfToken,
            'operation'  => 'ignore',
            'target_id'  => (string)$targetId,
        ]);
        $I->seeResponseCodeIs(200);

        $response = $I->grabJson();
        $I->assertIsArray($response);
        $I->assertTrue($response['success']);
        $I->assertSame(
            'ignored',
            (string)$dbLayer->select('health_status')->from(Manifest::TARGET_TABLE)
                ->where('id = :id')->setParameter('id', $targetId)->execute()->result(),
        );
    }

    /**
     * @param array<mixed> $targets
     * @return array<string, mixed>
     */
    private function findTarget(array $targets, string $url): array
    {
        foreach ($targets as $target) {
            if (\is_array($target) && ($target['normalized_url'] ?? null) === $url) {
                return $target;
            }
        }

        throw new \RuntimeException('Target not found: ' . $url);
    }

    private function insertPost(DbLayer $dbLayer, string $slug, string $body): int
    {
        $now = time();
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'parent_id'    => 'NULL',
            'slug_scope'   => "'root'",
            'slug'         => ':slug',
            'title'        => ':title',
            'excerpt'      => "''",
            'body'         => ':body',
            'created_at'   => ':now',
            'published_at' => ':now',
            'updated_at'   => ':now',
            'published'    => '1',
        ])->execute([
            'content_type' => ContentType::POST->value,
            'slug'         => $slug,
            'title'        => $slug,
            'body'         => $body,
            'now'          => $now,
        ]);

        return (int)$dbLayer->insertId();
    }

    private function markBrokenWithArchive(DbLayer $dbLayer, int $targetId, string $archiveUrl): void
    {
        $dbLayer->update(Manifest::TARGET_TABLE)
            ->set('health_status', "'broken'")
            ->set('archive_status', "'available'")
            ->set('archive_url', ':archive_url')->setParameter('archive_url', $archiveUrl)
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute();
    }

    private function markBroken(DbLayer $dbLayer, int $targetId): void
    {
        $dbLayer->update(Manifest::TARGET_TABLE)
            ->set('health_status', "'broken'")
            ->set('archive_status', "'unchecked'")
            ->set('archive_url', 'NULL')
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute();
    }

    private function targetId(DbLayer $dbLayer, string $url): int
    {
        return (int)$dbLayer->select('id')->from(Manifest::TARGET_TABLE)
            ->where('normalized_url = :url')->setParameter('url', $url)
            ->execute()->result();
    }

    private function configValue(DbLayer $dbLayer, string $name): string
    {
        return (string)$dbLayer->select('value')->from('config')
            ->where('name = :name')->setParameter('name', $name)
            ->execute()->result();
    }

    private function contentLinkCount(DbLayer $dbLayer): int
    {
        return (int)$dbLayer->select('COUNT(*)')->from(Manifest::CONTENT_LINK_TABLE)->execute()->result();
    }

    private function shutdownQueue(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(<<<'SQL'
            CREATE TABLE queue (
                id TEXT NOT NULL,
                code TEXT NOT NULL,
                payload TEXT NOT NULL,
                generation INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                available_at INTEGER NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                failed_at INTEGER,
                PRIMARY KEY (id, code)
            )
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE background_leases (
                name TEXT NOT NULL PRIMARY KEY,
                owner TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            )
            SQL);
        $pdo->exec("INSERT INTO background_leases (name, owner, expires_at) VALUES ('" . QueueSchema::RUNNER_LEASE . "', '', 0)");
        $pdo->exec('CREATE TABLE config (name TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL)');

        $statement = $pdo->prepare('INSERT INTO config (name, value) VALUES (:name, :value)');
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the shutdown queue fixture.');
        }

        $statement->execute(['name' => 'REGISTER_LAST_MAINTENANCE', 'value' => (string)time()]);

        return $pdo;
    }

    /** @return array<string, mixed>|false */
    private function queueJob(\PDO $pdo, int $targetId): array|false
    {
        $statement = $pdo->prepare('SELECT * FROM queue WHERE id = :id AND code = :code');
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the shutdown queue lookup.');
        }

        $statement->execute([
            'id'   => LinkQueue::targetJobId($targetId),
            'code' => LinkQueue::CHECK_CODE,
        ]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    private function queueCount(\PDO $pdo): int
    {
        $statement = $pdo->query('SELECT COUNT(*) FROM queue');
        if ($statement === false) {
            throw new \RuntimeException('Unable to count the shutdown queue fixture.');
        }

        return (int)$statement->fetchColumn();
    }
}

/** @internal */
final class CountingWaybackClient implements WaybackClientInterface
{
    public int $lookups = 0;

    #[\Override]
    public function lookup(string $url, int $referenceTime): ArchiveLookupResult
    {
        ++$this->lookups;
        return ArchiveLookupResult::missing();
    }
}

/** @internal */
final class SequencedLinkProbe implements LinkProbeInterface
{
    /** @var list<LinkProbeState> */
    public array $states = [];

    /** @param list<LinkProbeStep> $steps */
    public function __construct(private array $steps)
    {
    }

    #[\Override]
    public function step(LinkProbeState $state): LinkProbeStep
    {
        $this->states[] = $state;

        return array_shift($this->steps)
            ?? throw new \LogicException('No fake link-probe step remains.');
    }
}

/** @internal */
final readonly class FailingQueuePublisher extends QueuePublisher
{
    /** @param array<mixed> $payload */
    #[\Override]
    public function publish(string $id, string $code, array $payload = [], ?int $availableAt = null): void
    {
        throw new \PDOException('Forced queue publication failure.');
    }
}
