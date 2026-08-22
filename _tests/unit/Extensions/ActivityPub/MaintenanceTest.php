<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueuePublisher;
use Register\Extension\activitypub\Application\ActivityPubMaintenanceQueueHandler;
use Register\Extension\activitypub\Application\ActivityPubMaintenanceTask;
use Register\Extension\activitypub\Application\FederationLifecycleService;
use Register\Extension\activitypub\Delivery\DeliveryPlanner;
use Register\Extension\activitypub\Delivery\DeliveryQueue;
use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Domain\ActorKind;
use Register\Extension\activitypub\Domain\ActorType;
use Register\Extension\activitypub\Domain\InboxState;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\LocalHandle;
use Register\Extension\activitypub\Domain\NewLocalActor;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Infrastructure\ActivityPubHousekeepingRepository;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;
use Register\Extension\activitypub\Infrastructure\DeliveryRepository;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\FetchedRemoteActor;
use Register\Extension\activitypub\Infrastructure\InboxRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\NewInboxItem;
use Register\Extension\activitypub\Infrastructure\NewStoredActivity;
use Register\Extension\activitypub\Infrastructure\NotificationRepository;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Inbox\InboxQueue;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\LocalActivityDocumentBuilder;
use Register\Extension\activitypub\Security\RsaCrypto;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;

final class MaintenanceTest extends Unit
{
    public function testRetentionRedactsPayloadsAndPrunesOnlyEligibleOperationalRows(): void
    {
        [$pdo, $dbLayer] = $this->database();
        $now = 20_000_000;
        $repository = new ActivityPubHousekeepingRepository($dbLayer);

        $inboxRepository = new InboxRepository($dbLayer);
        $rawBody = '{"id":"https://remote.example/activities/old","type":"Unknown"}';
        $inboxRepository->receive(new NewInboxItem(
            null,
            'Unknown',
            'https://remote.example/activities/old',
            'https://remote.example/users/alice',
            'https://remote.example/users/alice#key',
            'legacy',
            'https://remote.example',
            $rawBody,
            '{}',
            $now - 8 * 24 * 60 * 60,
        ));
        $claimedInbox = $inboxRepository->claimNext($now - 8 * 24 * 60 * 60);
        self::assertNotNull($claimedInbox);
        $inboxRepository->markTerminal(
            $claimedInbox,
            InboxState::PROCESSED,
            '',
            'Processed.',
            $now - 8 * 24 * 60 * 60 + 1,
        );
        $rawHash = hash('sha256', $rawBody);
        self::assertSame(1, $repository->redactExpiredInboxPayloads($now));
        $inbox = $dbLayer->select('*')->from(ActivityPubSchema::INBOX_TABLE)->execute()->fetchAssoc();
        self::assertIsArray($inbox);
        self::assertSame('', $inbox['raw_body']);
        self::assertSame($rawHash, $inbox['body_hash']);

        $remotePair = (new RsaCrypto())->generateKeyPair();
        $remoteDocument = '{"id":"https://remote.example/users/alice"}';
        $remoteActor = (new RemoteActorRepository($dbLayer))->save(new FetchedRemoteActor(
            'https://remote.example/users/alice',
            'Person',
            'alice',
            'Alice',
            'https://remote.example/users/alice/inbox',
            null,
            'https://remote.example/users/alice#key',
            $remotePair->publicKeyPem,
            [],
            $remoteDocument,
            hash('sha256', $remoteDocument),
            $now - 40 * 24 * 60 * 60,
            $now - 39 * 24 * 60 * 60,
        ));
        $currentSnapshotId = (int)$dbLayer->select('current_snapshot_id')
            ->from(ActivityPubSchema::REMOTE_ACTOR_TABLE)
            ->where('id = :id')->setParameter('id', $remoteActor->id)
            ->execute()
            ->result()
        ;
        $dbLayer->update(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE)
            ->set('retain_until', ':retain_until')->setParameter('retain_until', $now - 1)
            ->where('id = :id')->setParameter('id', $currentSnapshotId)
            ->execute()
        ;
        $dbLayer->insert(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE)->values([
            'subject_type'       => ':subject_type',
            'subject_id'         => ':subject_id',
            'body_hash'          => ':body_hash',
            'document_json'      => ':document_json',
            'verification_state' => ':verification_state',
            'fetched_at'         => ':fetched_at',
            'retain_until'       => ':retain_until',
        ])->execute([
            'subject_type'       => 'actor',
            'subject_id'         => $remoteActor->id,
            'body_hash'          => hash('sha256', 'detached'),
            'document_json'      => '{}',
            'verification_state' => 'validated',
            'fetched_at'         => $now - 100,
            'retain_until'       => $now - 1,
        ]);
        self::assertSame(1, $repository->pruneDetachedRemoteSnapshots($now));
        self::assertSame(1, $this->tableCount($pdo, ActivityPubSchema::REMOTE_SNAPSHOT_TABLE));
        self::assertSame($currentSnapshotId, (int)$dbLayer->select('id')->from(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE)->execute()->result());

        foreach ([
            ['old', $now - 2 * 24 * 60 * 60, $now - 2 * 24 * 60 * 60],
            ['current', $now - 60, $now - 60],
        ] as [$dimension, $blockedUntil, $updatedAt]) {
            $dbLayer->insert(ActivityPubSchema::RATE_LIMIT_TABLE)->values([
                'bucket_hash'      => ':bucket_hash',
                'dimension'        => ':dimension',
                'window_started_at' => ':window_started_at',
                'request_count'    => '1',
                'blocked_until'    => ':blocked_until',
                'updated_at'       => ':updated_at',
            ])->execute([
                'bucket_hash'       => hash('sha256', $dimension),
                'dimension'         => $dimension,
                'window_started_at' => $updatedAt,
                'blocked_until'     => $blockedUntil,
                'updated_at'        => $updatedAt,
            ]);
        }

        self::assertSame(1, $repository->pruneRateLimits($now));
        self::assertSame(1, $this->tableCount($pdo, ActivityPubSchema::RATE_LIMIT_TABLE));

        $notifications = new NotificationRepository($dbLayer, new CanonicalJson());
        $readId = $notifications->create(null, 'test', 'object', 1, [], $now - 100 * 24 * 60 * 60);
        $notifications->create(null, 'test', 'object', 2, [], $now - 100 * 24 * 60 * 60);
        $dbLayer->update(ActivityPubSchema::NOTIFICATION_TABLE)
            ->set('state', ':state')->setParameter('state', 'read')
            ->set('read_at', ':read_at')->setParameter('read_at', $now - 100 * 24 * 60 * 60)
            ->where('id = :id')->setParameter('id', $readId)
            ->execute()
        ;
        self::assertSame(1, $repository->pruneReadNotifications($now));
        self::assertSame(1, $this->tableCount($pdo, ActivityPubSchema::NOTIFICATION_TABLE));

        $actorRepository = new LocalActorRepository($dbLayer);
        $actorId = $actorRepository->insert(new NewLocalActor(
            (new PublicIdGenerator())->generate(),
            ActorKind::SITE,
            null,
            ActorType::SERVICE,
            new LocalHandle('maintenance'),
            'Maintenance',
            '',
            'https://local.example/',
        ), LocalActorState::ACTIVE, $now - 100 * 24 * 60 * 60);
        $body = '{"type":"Create"}';
        $activity = (new LocalFederationRepository($dbLayer))->insertActivity(new NewStoredActivity(
            (new PublicIdGenerator())->generate(),
            $actorId,
            null,
            'Create',
            'direct',
            ActivityDeliveryIntent::DIRECT,
            'maintenance-delivery',
            $body,
            hash('sha256', $body),
            $now - 100 * 24 * 60 * 60,
            $now - 100 * 24 * 60 * 60,
        ));
        $deliveryRepository = new DeliveryRepository($dbLayer);
        $old = $now - 100 * 24 * 60 * 60;
        self::assertSame(1, $deliveryRepository->planDirect(
            $activity,
            'https://remote.example/inbox',
            ['https://remote.example/users/alice'],
            $old,
        ));
        $delivery = $deliveryRepository->claimNext($old);
        self::assertNotNull($delivery);
        $deliveryRepository->markDelivered($delivery, 202, $old + 1);
        $deliveryRepository->recordAttempt($delivery, 'delivered', 202, '', 'Accepted.', $old, $old + 1);
        self::assertSame(1, $repository->pruneDeliveryAttempts($now));
        self::assertSame(0, $this->tableCount($pdo, ActivityPubSchema::DELIVERY_ATTEMPT_TABLE));
        self::assertSame(1, $repository->pruneTerminalDeliveries($now));
        self::assertSame(0, $this->tableCount($pdo, ActivityPubSchema::DELIVERY_TABLE));
        self::assertSame(1, $this->tableCount($pdo, ActivityPubSchema::ACTOR_TABLE));
        self::assertSame(1, $this->tableCount($pdo, ActivityPubSchema::REMOTE_ACTOR_TABLE));
        self::assertSame(1, $this->tableCount($pdo, ActivityPubSchema::ACTIVITY_TABLE));

        foreach ([
            ['failed', $old],
            ['activated', $old],
        ] as [$state, $expiresAt]) {
            $dbLayer->insert(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE)->values([
                'id'               => ':id',
                'actor_id'         => ':actor_id',
                'canonical_origin' => ':canonical_origin',
                'base_path'        => ':base_path',
                'state'            => ':state',
                'next_step'        => '3',
                'results_json'     => ':results_json',
                'created_at'       => ':created_at',
                'updated_at'       => ':updated_at',
                'expires_at'       => ':expires_at',
                'completed_at'     => ':completed_at',
            ])->execute([
                'id'               => (new PublicIdGenerator())->generate(),
                'actor_id'         => $actorId,
                'canonical_origin' => 'https://local.example',
                'base_path'        => '',
                'state'            => $state,
                'results_json'     => '[]',
                'created_at'       => $old,
                'updated_at'       => $old,
                'expires_at'       => $expiresAt,
                'completed_at'     => $old,
            ]);
        }

        self::assertSame(1, $repository->pruneExpiredActivationAttempts($now));
        self::assertSame(1, $this->tableCount($pdo, ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE));
    }

    public function testMaintenanceSchedulingAndHandlerAdvanceOneOperationPerGeneration(): void
    {
        [$pdo, $dbLayer] = $this->database();
        $publisher = new QueuePublisher($pdo, '');
        $task = new ActivityPubMaintenanceTask($publisher);
        $task->schedule(10_000, new QueueExecutionBudget(1.0, static fn(): float => 0.0));
        $task->schedule(10_001, new QueueExecutionBudget(1.0, static fn(): float => 0.0));

        $row = $dbLayer->select('*')->from('queue')->execute()->fetchAssoc();
        self::assertIsArray($row);
        self::assertSame(1, (int)$row['generation']);
        self::assertSame(['operation' => 0], json_decode((string)$row['payload'], true, 8, JSON_THROW_ON_ERROR));

        $deliveryRepository = new DeliveryRepository($dbLayer);
        $deliveryQueue = new DeliveryQueue($publisher, $deliveryRepository);
        $inboxRepository = new InboxRepository($dbLayer);
        $stateRepository = new FederationStateRepository($dbLayer);
        $lifecycle = new FederationLifecycleService(
            $stateRepository,
            new LocalActorRepository($dbLayer),
            new LocalFederationRepository($dbLayer),
            $deliveryRepository,
            $inboxRepository,
            new FederationUrlGeneratorFactory($stateRepository),
            new PublicIdGenerator(),
            new LocalActivityDocumentBuilder(),
            new CanonicalJson(),
            new DeliveryPlanner($deliveryRepository, $deliveryQueue),
            $deliveryQueue,
            new InboxQueue($publisher, $inboxRepository),
            new PortableDatabaseTransaction($pdo),
        );
        $handler = new ActivityPubMaintenanceQueueHandler(
            new ActivityPubHousekeepingRepository($dbLayer),
            $lifecycle,
            $publisher,
            static fn(): int => 10_002,
        );
        $handler->handle(
            ActivityPubMaintenanceQueueHandler::JOB_ID,
            ActivityPubMaintenanceQueueHandler::CODE,
            ['operation' => 0],
            new QueueExecutionBudget(1.0, static fn(): float => 0.0),
        );
        $row = $dbLayer->select('*')->from('queue')->execute()->fetchAssoc();
        self::assertIsArray($row);
        self::assertSame(2, (int)$row['generation']);
        self::assertSame(['operation' => 1], json_decode((string)$row['payload'], true, 8, JSON_THROW_ON_ERROR));
        self::assertSame(10_003, (int)$row['available_at']);
    }

    /** @return array{\PDO, DbLayerSqlite} */
    private function database(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE queue (
            id TEXT NOT NULL,
            code TEXT NOT NULL,
            payload TEXT NOT NULL,
            generation INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            available_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL,
            last_error TEXT,
            failed_at INTEGER,
            PRIMARY KEY (id, code)
        )');
        $dbLayer = new DbLayerSqlite($pdo);
        ActivityPubSchema::install($dbLayer);

        return [$pdo, $dbLayer];
    }

    private function tableCount(\PDO $pdo, string $table): int
    {
        $statement = $pdo->query('SELECT COUNT(*) FROM ' . $table);
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to count the ActivityPub maintenance fixture.');
        }

        return (int)$statement->fetchColumn();
    }
}
