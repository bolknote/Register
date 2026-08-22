<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use IntegrationTester;
use Psr\Log\NullLogger;
use Register\Backup\BackupQueueHandler;
use Register\Content\ContentPublicationQueueHandler;
use Register\Content\ContentPublicationScheduler;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Comment\Antispam\SpamMaintenance;
use Register\Core\Comment\Antispam\SpamMaintenanceQueueHandler;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\BackgroundWorkRunner;
use Register\Core\Queue\QueueConsumer;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueueHandlerRegistry;
use Register\Core\Queue\QueueMonitor;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Queue\QueueRecovery;
use Register\Core\Queue\QueueRunnerLease;
use Register\Core\Queue\QueueSchema;
use Register\Core\Queue\QueueTimeBudgetExceeded;
use Register\Core\Queue\ScheduledMaintenance;

/** @group queue */
final class QueueCest
{
    public function backgroundRunnerDoesNotEnterAnActiveRequestTransaction(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $publisher = new QueuePublisher($pdo, '');
        $publisher->publish('transaction', 'test');

        /** @var BackgroundWorkRunner $runner */
        $runner = $I->grabService(BackgroundWorkRunner::class);
        $I->assertSame(0, $runner->run());
        $I->assertIsArray($this->findJob($pdo, 'transaction', 'test'));
    }

    public function invalidBackgroundRunnerBudgetDoesNotAcquireLease(IntegrationTester $I): void
    {
        $pdo = $this->pdo($I);

        /** @var BackgroundWorkRunner $runner */
        $runner = $I->grabService(BackgroundWorkRunner::class);
        $I->expectThrowable(
            \InvalidArgumentException::class,
            static fn(): int => $runner->run(INF),
        );

        $lease = new QueueRunnerLease($pdo, '');
        $I->assertTrue($lease->acquire(1));
        $lease->release();
    }

    public function maintenanceRunsAtMostOncePerInterval(IntegrationTester $I): void
    {
        $pdo = $this->pdo($I);
        $pdo->exec("UPDATE config SET value = '0' WHERE name = 'REGISTER_LAST_MAINTENANCE'");

        /** @var ScheduledMaintenance $maintenance */
        $maintenance = $I->grabService(ScheduledMaintenance::class);
        $I->assertTrue($maintenance->runIfDue(1_800_000_000));
        $I->assertFalse($maintenance->runIfDue(1_800_000_001));

        $statement = $pdo->prepare('SELECT id FROM queue WHERE code = :code ORDER BY id');
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the maintenance queue test query.');
        }

        $statement->execute(['code' => SpamMaintenanceQueueHandler::CODE]);
        $expectedOperations = SpamMaintenance::OPERATIONS;
        sort($expectedOperations);
        $I->assertSame($expectedOperations, $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function requestSchedulingPreservesExistingWorkAndSeedsAutomaticBackup(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $publisher = new QueuePublisher($pdo, '');
        $now       = 1_800_000_000;

        /** @var ScheduledMaintenance $maintenance */
        $maintenance = $I->grabService(ScheduledMaintenance::class);
        $maintenance->scheduleRequestWork($now);

        $I->assertFalse($this->findJob(
            $pdo,
            ContentPublicationQueueHandler::JOB_ID,
            ContentPublicationQueueHandler::CODE,
        ));

        $this->insertScheduledContent($pdo, $now);
        $maintenance->scheduleRequestWork($now);

        $pdo->exec(
            "UPDATE queue SET attempts = 3, last_error = 'keep-me', failed_at = 123 "
            . "WHERE id = '" . ContentPublicationQueueHandler::JOB_ID . "' "
            . "AND code = '" . ContentPublicationQueueHandler::CODE . "'"
        );
        $maintenance->scheduleRequestWork($now + 1);

        $publicationJob = $this->job(
            $pdo,
            ContentPublicationQueueHandler::JOB_ID,
            ContentPublicationQueueHandler::CODE,
        );
        $I->assertSame(1, (int)$publicationJob['generation']);
        $I->assertSame(3, (int)$publicationJob['attempts']);
        $I->assertSame('keep-me', $publicationJob['last_error']);
        $I->assertSame(123, (int)$publicationJob['failed_at']);

        $pdo->exec("UPDATE config SET value = '0' WHERE name = 'REGISTER_LAST_MAINTENANCE'");
        /** @var ContentPublicationScheduler $publicationScheduler */
        $publicationScheduler = $I->grabService(ContentPublicationScheduler::class);
        $enabledMaintenance   = new ScheduledMaintenance($pdo, '', $publisher, $publicationScheduler, true);
        $I->assertTrue($enabledMaintenance->runIfDue($now));
        $I->assertIsArray($this->findJob($pdo, BackupQueueHandler::JOB_ID, BackupQueueHandler::CODE));
    }

    public function maintenanceContinuesInBoundedQueueBatches(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $now       = time();
        $publisher = new QueuePublisher($pdo, '');
        $pdo->exec('DELETE FROM spam_form_nonces');
        for ($i = 0; $i <= SpamMaintenanceQueueHandler::BATCH_SIZE; ++$i) {
            $statement = $pdo->prepare(
                'INSERT INTO spam_form_nonces (nonce_hash, expires_at) VALUES (:nonce_hash, :expires_at)'
            );
            if ($statement === false) {
                throw new \RuntimeException('Unable to prepare a form nonce test query.');
            }

            $statement->execute([
                'nonce_hash' => hash('sha256', 'maintenance-batch-' . $i),
                'expires_at' => $now - 1,
            ]);
        }

        /** @var SpamMaintenanceQueueHandler $handler */
        $handler  = $I->grabService(SpamMaintenanceQueueHandler::class);
        $consumer = $this->consumer($pdo, $handler);
        $publisher->publish(
            'form_nonces',
            SpamMaintenanceQueueHandler::CODE,
            ['scheduled_at' => $now],
        );

        $I->assertTrue($consumer->runQueue($now));
        $I->assertSame(1, $this->expiredNonceCount($pdo, $now));
        $I->assertSame(2, (int)$this->job($pdo, 'form_nonces', SpamMaintenanceQueueHandler::CODE)['generation']);

        $future = time() + 10;
        $I->assertTrue($consumer->runQueue($future));
        $I->assertSame(0, $this->expiredNonceCount($pdo, $now));
        $I->assertTrue($consumer->runQueue($future));
        $I->assertFalse($this->findJob($pdo, 'form_nonces', SpamMaintenanceQueueHandler::CODE));
    }

    public function runnerLeaseSerializesNodesAndRecoversAfterExpiry(IntegrationTester $I): void
    {
        $pdo = $this->pdo($I);
        $pdo->exec(
            "UPDATE " . QueueSchema::LEASE_TABLE . " SET owner = '', expires_at = 0 WHERE name = '"
            . QueueSchema::RUNNER_LEASE . "'"
        );

        $first  = new QueueRunnerLease($pdo, '');
        $second = new QueueRunnerLease($pdo, '');
        $I->assertTrue($first->acquire(30));
        $I->assertFalse($second->acquire(30));
        $I->assertTrue((new QueueMonitor($pdo, ''))->status()['runner_active']);

        $first->release();
        $I->assertFalse((new QueueMonitor($pdo, ''))->status()['runner_active']);
        $I->assertTrue($second->acquire(30));

        $second->release();

        $pdo->exec(
            "UPDATE " . QueueSchema::LEASE_TABLE . " SET owner = 'dead-worker', expires_at = 0 WHERE name = '"
            . QueueSchema::RUNNER_LEASE . "'"
        );
        $recovered = new QueueRunnerLease($pdo, '');
        $I->assertTrue($recovered->acquire(30));
        $recovered->release();

        $stale       = new QueueRunnerLease($pdo, '');
        $replacement = new QueueRunnerLease($pdo, '');
        $contender   = new QueueRunnerLease($pdo, '');
        $I->assertTrue($stale->acquire(1));
        $pdo->exec(
            "UPDATE " . QueueSchema::LEASE_TABLE . " SET expires_at = 0 WHERE name = '"
            . QueueSchema::RUNNER_LEASE . "'"
        );
        $I->assertTrue($replacement->acquire(30));

        $stale->release();
        $I->assertFalse($contender->acquire(30));
        $replacement->release();
    }

    public function cleanSchemaContainsDurableQueueAndRunnerLease(IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);

        foreach (['generation', 'created_at', 'updated_at', 'available_at', 'attempts', 'last_error', 'failed_at'] as $field) {
            $I->assertTrue($dbLayer->fieldExists('queue', $field));
        }

        $I->assertTrue($dbLayer->indexExists('queue', 'due_idx'));
        $I->assertTrue($dbLayer->tableExists(QueueSchema::LEASE_TABLE));

        $statement = $this->pdo($I)->query("SELECT value FROM config WHERE name = 'REGISTER_LAST_MAINTENANCE'");
        if ($statement === false) {
            throw new \RuntimeException('Unable to query migrated maintenance config.');
        }

        $I->assertSame('0', $statement->fetchColumn());
    }

    public function executionBudgetDefersWithoutConsumingRetryAttempts(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $publisher = new QueuePublisher($pdo, '');
        $handler   = new QueueTestHandler();
        $handler->callback = static function (): never {
            throw new QueueTimeBudgetExceeded('Expected cooperative stop.');
        };
        $consumer = $this->consumer($pdo, $handler);
        $now      = time();
        $publisher->publish('budget', 'test');

        $I->assertTrue($consumer->runQueue($now, new QueueExecutionBudget(5.0)));
        $row = $this->job($pdo, 'budget', 'test');
        $I->assertSame(0, (int)$row['attempts']);
        $I->assertSame($now + 1, (int)$row['available_at']);
        $I->assertNull($row['last_error']);
    }

    public function insufficientBudgetDoesNotStartOrMutateJob(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $publisher = new QueuePublisher($pdo, '');
        $handler   = new QueueTestHandler();
        $consumer  = $this->consumer($pdo, $handler);
        $clock     = 1.0;
        $publisher->publish('too-late', 'test');
        $before = $this->job($pdo, 'too-late', 'test');

        $budget = new QueueExecutionBudget(0.005, static function () use (&$clock): float {
            return $clock;
        });
        $I->assertFalse($consumer->runQueue(budget: $budget));

        $I->assertSame([], $handler->calls);
        $I->assertSame($before, $this->job($pdo, 'too-late', 'test'));
    }

    public function expiredBudgetDoesNotMutateUnknownJob(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $publisher = new QueuePublisher($pdo, '');
        $consumer  = $this->consumer($pdo, new QueueTestHandler());
        $clock     = 1.0;
        $publisher->publish('unknown-too-late', 'missing');
        $before = $this->job($pdo, 'unknown-too-late', 'missing');

        $budget = new QueueExecutionBudget(0.01, static function () use (&$clock): float {
            return $clock;
        });
        $clock += 0.01;

        $I->assertFalse($consumer->runQueue(budget: $budget));
        $I->assertSame($before, $this->job($pdo, 'unknown-too-late', 'missing'));
    }

    public function expensiveHeadJobDoesNotBlockRunnableWorkBehindIt(IntegrationTester $I): void
    {
        $pdo          = $this->pdo($I);
        $publisher    = new QueuePublisher($pdo, '');
        $shortHandler = new QueueTestHandler();
        $consumer     = new QueueConsumer(
            $pdo,
            '',
            new NullLogger(),
            new QueueHandlerRegistry(new SlowQueueTestHandler(), $shortHandler),
        );
        for ($jobNumber = 0; $jobNumber < 40; ++$jobNumber) {
            $publisher->publish(\sprintf('a-slow-%02d', $jobNumber), 'slow');
        }

        $publisher->publish('z-short', 'test');

        $I->assertTrue($consumer->runQueue(budget: new QueueExecutionBudget(1.0)));

        $I->assertIsArray($this->findJob($pdo, 'a-slow-00', 'slow'));
        $I->assertFalse($this->findJob($pdo, 'z-short', 'test'));
        $I->assertSame([['z-short', 'test', []]], $shortHandler->calls);
    }

    public function queueStatusAndExplicitDeadLetterRecoveryAreObservable(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $publisher = new QueuePublisher($pdo, '');
        $now       = time();
        $monitor   = new QueueMonitor($pdo, '');
        $empty     = $monitor->status($now);
        $I->assertSame(0, $empty['total']);
        $I->assertSame(0, $empty['ready']);
        $I->assertSame(0, $empty['delayed']);
        $I->assertSame(0, $empty['failed']);
        $I->assertNull($empty['oldest_ready_age']);

        $publisher->publish('ready', 'test', availableAt: $now);
        $publisher->publish('delayed', 'test', availableAt: $now + 60);
        $publisher->publish('failed', 'test', availableAt: $now);

        $pdo->exec(
            "UPDATE queue SET created_at = " . ($now - 40) . " WHERE id = 'ready' AND code = 'test'"
        );
        $pdo->exec(
            "UPDATE queue SET attempts = 5, last_error = 'failed', failed_at = " . $now
            . " WHERE id = 'failed' AND code = 'test'"
        );

        $status = $monitor->status($now);
        $I->assertSame(3, $status['total']);
        $I->assertSame(1, $status['ready']);
        $I->assertSame(1, $status['delayed']);
        $I->assertSame(1, $status['failed']);
        $I->assertSame(40, $status['oldest_ready_age']);

        $recovery = new QueueRecovery($pdo, '');
        $I->assertTrue($recovery->retryFailed('failed', 'test', $now + 1));
        $I->assertFalse($recovery->retryFailed('failed', 'test', $now + 1));

        $row = $this->job($pdo, 'failed', 'test');
        $I->assertSame(2, (int)$row['generation']);
        $I->assertSame(0, (int)$row['attempts']);
        $I->assertNull($row['last_error']);
        $I->assertNull($row['failed_at']);
    }

    public function publishUpdatesGenerationAndRevivesFailedJob(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $publisher = new QueuePublisher($pdo, '');
        $publisher->publish('same-id', 'test', ['version' => 1]);

        $pdo->exec("UPDATE queue SET attempts = 4, last_error = 'error', failed_at = 123 WHERE id = 'same-id' AND code = 'test'");
        $publisher->publish('same-id', 'test', ['version' => 2]);

        $row = $this->job($pdo, 'same-id', 'test');
        $I->assertSame(2, (int)$row['generation']);
        $I->assertSame('{"version":2}', $row['payload']);
        $I->assertSame(0, (int)$row['attempts']);
        $I->assertNull($row['last_error']);
        $I->assertNull($row['failed_at']);
    }

    public function successfulJobIsAcknowledged(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $handler   = new QueueTestHandler();
        $consumer  = $this->consumer($pdo, $handler);
        $publisher = new QueuePublisher($pdo, '');
        $publisher->publish('success', 'test', ['value']);

        $I->assertTrue($consumer->runQueue());
        $I->assertFalse($consumer->runQueue());
        $I->assertSame([['success', 'test', ['value']]], $handler->calls);
        $I->assertFalse($this->findJob($pdo, 'success', 'test'));
    }

    public function republishDuringHandlerPreservesNewGeneration(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $publisher = new QueuePublisher($pdo, '');
        $handler   = new QueueTestHandler();
        $handler->callback = function () use ($publisher, $handler): void {
            $handler->callback = null;
            $publisher->publish('race', 'test', ['version' => 2]);
        };
        $consumer = $this->consumer($pdo, $handler);
        $publisher->publish('race', 'test', ['version' => 1]);

        $I->assertTrue($consumer->runQueue());
        $row = $this->job($pdo, 'race', 'test');
        $I->assertSame(2, (int)$row['generation']);
        $I->assertSame('{"version":2}', $row['payload']);

        $I->assertTrue($consumer->runQueue());
        $I->assertFalse($this->findJob($pdo, 'race', 'test'));
    }

    public function failedJobUsesBackoffAndEventuallyStops(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $publisher = new QueuePublisher($pdo, '');
        $handler   = new QueueTestHandler();
        $handler->callback = static function (): never {
            throw new \RuntimeException('Expected failure');
        };
        $consumer = $this->consumer($pdo, $handler);
        $publisher->publish('poison', 'test');

        $now = time();
        for ($attempt = 1; $attempt <= QueueConsumer::MAX_ATTEMPTS; ++$attempt) {
            $I->assertTrue($consumer->runQueue($now));
            $row = $this->job($pdo, 'poison', 'test');
            $I->assertSame($attempt, (int)$row['attempts']);

            if ($attempt < QueueConsumer::MAX_ATTEMPTS) {
                $I->assertNull($row['failed_at']);
                $availableAt = (int)$row['available_at'];
                $I->assertGreaterThan($now, $availableAt);
                $I->assertFalse($consumer->runQueue($availableAt - 1));
                $now = $availableAt;
            }
        }

        $row = $this->job($pdo, 'poison', 'test');
        $I->assertNotNull($row['failed_at']);
        $I->assertStringContainsString('Expected failure', (string)$row['last_error']);
        $I->assertFalse($consumer->runQueue($now + 10_000));
    }

    public function unknownCodeIsRetainedForRetry(IntegrationTester $I): void
    {
        $pdo       = $this->pdo($I);
        $publisher = new QueuePublisher($pdo, '');
        $consumer  = $this->consumer($pdo, new QueueTestHandler());
        $publisher->publish('unknown', 'missing');

        $I->assertTrue($consumer->runQueue());
        $row = $this->job($pdo, 'unknown', 'missing');
        $I->assertSame(1, (int)$row['attempts']);
        $I->assertStringContainsString('No queue handler', (string)$row['last_error']);
    }

    private function insertScheduledContent(\PDO $pdo, int $scheduledAt): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO ' . ContentSchema::TABLE_NAME . ' '
            . '(content_type, slug_scope, slug, title, excerpt, body, created_at, published_at, scheduled_at, updated_at, published) '
            . "VALUES (:content_type, 'root', :slug, :title, '', '<p>Scheduled content</p>', :created_at, NULL, :scheduled_at, :updated_at, 0)"
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the scheduled-content queue fixture.');
        }

        $statement->execute([
            'content_type' => ContentType::POST->value,
            'slug'         => 'queue-scheduled-content-' . $scheduledAt,
            'title'        => 'Queue scheduled content',
            'created_at'   => $scheduledAt - 60,
            'scheduled_at' => $scheduledAt,
            'updated_at'   => $scheduledAt - 60,
        ]);
    }

    private function pdo(IntegrationTester $I): \PDO
    {
        /** @var \PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $pdo->exec('DELETE FROM queue');
        return $pdo;
    }

    private function consumer(\PDO $pdo, QueueHandlerInterface $handler): QueueConsumer
    {
        return new QueueConsumer($pdo, '', new NullLogger(), new QueueHandlerRegistry($handler));
    }

    private function expiredNonceCount(\PDO $pdo, int $now): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM spam_form_nonces WHERE expires_at < :now');
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare an expired nonce count query.');
        }

        $statement->execute(['now' => $now]);
        return (int)$statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function job(\PDO $pdo, string $id, string $code): array
    {
        $row = $this->findJob($pdo, $id, $code);
        if (!\is_array($row)) {
            throw new \RuntimeException('Expected queue job was not found.');
        }

        return $row;
    }

    /** @return array<string, mixed>|false */
    private function findJob(\PDO $pdo, string $id, string $code): array|false
    {
        $statement = $pdo->prepare('SELECT * FROM queue WHERE id = :id AND code = :code');
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare queue test query.');
        }

        $statement->execute(['id' => $id, 'code' => $code]);
        return $statement->fetch(\PDO::FETCH_ASSOC);
    }
}

final class QueueTestHandler implements QueueHandlerInterface
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $calls = [];

    public ?\Closure $callback = null;

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return ['test'];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 0.01;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        $budget->checkpoint();
        $this->calls[] = [$id, $code, $payload];
        if ($this->callback instanceof \Closure) {
            ($this->callback)();
        }
    }
}

final class SlowQueueTestHandler implements QueueHandlerInterface
{
    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return ['slow'];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 10.0;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        throw new \LogicException('The slow test handler must not be started.', 0);
    }
}
