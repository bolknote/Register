<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace integration;

use IntegrationTester;
use Psr\Log\NullLogger;
use Register\Module\BaseModuleInstaller;
use Register\Module\BaseModuleRegistry;
use Register\Schema\SchemaMigrator;
use S2\Cms\Comment\Antispam\SpamMaintenance;
use S2\Cms\Comment\Antispam\SpamMaintenanceQueueHandler;
use S2\Cms\Framework\Container;
use S2\Cms\Pdo\DbLayerSqlite;
use S2\Cms\Pdo\PDO;
use S2\Cms\Pdo\SchemaBuilderInterface;
use S2\Cms\Queue\BackgroundWorkRunner;
use S2\Cms\Queue\QueueConsumer;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueueHandlerRegistry;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Queue\ScheduledMaintenance;

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

    public function maintenanceRunsAtMostOncePerInterval(IntegrationTester $I): void
    {
        $pdo = $this->pdo($I);
        $pdo->exec("UPDATE config SET value = '0' WHERE name = 'S2_LAST_MAINTENANCE'");

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

    public function revisionTwoMigrationPreservesLegacyQueueRows(IntegrationTester $I): void
    {
        $pdo     = new PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $dbLayer->createTable('config', static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('name', 191)
                ->addText('value', nullable: false)
                ->setPrimaryKey(['name'])
            ;
        });
        $dbLayer->createTable('queue', static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('id', 80, default: null)
                ->addString('code', 80, default: null)
                ->addText('payload', nullable: false)
                ->setPrimaryKey(['id', 'code'])
            ;
        });
        $dbLayer->insert('config')->values([
            'name'  => "'" . SchemaMigrator::CONFIG_KEY . "'",
            'value' => "'1'",
        ])->execute();
        $dbLayer->insert('queue')->values(['id' => "'legacy'", 'code' => "'test'", 'payload' => "'[]'"])->execute();

        $schemaMigrator = new SchemaMigrator(
            $dbLayer,
            new Container([]),
            new BaseModuleInstaller(new BaseModuleRegistry()),
        );
        $I->assertTrue($schemaMigrator->migrate());
        $I->assertSame(SchemaMigrator::LATEST_REVISION, $schemaMigrator->currentRevision());

        foreach (['generation', 'created_at', 'updated_at', 'available_at', 'attempts', 'last_error', 'failed_at'] as $field) {
            $I->assertTrue($dbLayer->fieldExists('queue', $field));
        }

        $I->assertTrue($dbLayer->indexExists('queue', 'due_idx'));

        $row = $this->job($pdo, 'legacy', 'test');
        $I->assertSame(1, (int)$row['generation']);
        $I->assertSame(0, (int)$row['available_at']);

        $statement = $pdo->query("SELECT value FROM config WHERE name = 'S2_LAST_MAINTENANCE'");
        if ($statement === false) {
            throw new \RuntimeException('Unable to query migrated maintenance config.');
        }

        $I->assertSame('0', $statement->fetchColumn());
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

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload): void
    {
        $this->calls[] = [$id, $code, $payload];
        if ($this->callback instanceof \Closure) {
            ($this->callback)();
        }
    }
}
