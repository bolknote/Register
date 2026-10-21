<?php

declare(strict_types = 1);

namespace unit\Cms\Pdo;

use Codeception\Test\Unit;
use Register\Core\Pdo\DatabaseTransaction;
use Register\Core\Pdo\PDO;

final class DatabaseTransactionTest extends Unit
{
    public function testFailedOperationRollsBackAndCanBeRetried(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE items (value INTEGER NOT NULL)');

        $transaction = new DatabaseTransaction($pdo);
        self::assertStorageFailure(static function () use ($transaction, $pdo): void {
            $transaction->run(static function () use ($pdo): void {
                $pdo->exec('INSERT INTO items VALUES (1)');
                $pdo->exec('INSERT INTO items VALUES (NULL)');
            });
        });

        self::assertFalse($pdo->inTransaction());
        $result = $pdo->query('SELECT COUNT(*) FROM items');
        self::assertNotFalse($result);
        self::assertSame(0, (int)$result->fetchColumn());
        self::assertSame(1, $transaction->run(static fn(): int|false => $pdo->exec('INSERT INTO items VALUES (2)')));
        $result = $pdo->query('SELECT value FROM items');
        self::assertNotFalse($result);
        self::assertSame([2], $result->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testNestedFailurePreservesOuterWorkAndDiscardsInnerCallbacks(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE items (value INTEGER NOT NULL)');

        $transaction = new DatabaseTransaction($pdo);
        $callbacks = new TransactionCallbackLog();
        $transaction->run(static function () use ($pdo, $transaction, $callbacks): void {
            $pdo->exec('INSERT INTO items VALUES (1)');
            $pdo->afterCommit(static function () use ($callbacks): void {
                $callbacks->add('outer');
            });
            self::assertStorageFailure(static function () use ($pdo, $transaction, $callbacks): void {
                // A different wrapper instance must not reuse the outer savepoint name.
                (new DatabaseTransaction($pdo))->run(static function () use ($pdo, $transaction, $callbacks): void {
                    $pdo->exec('INSERT INTO items VALUES (2)');
                    $transaction->run(static fn(): int|false => $pdo->exec('INSERT INTO items VALUES (3)'));
                    $pdo->afterCommit(static function () use ($callbacks): void {
                        $callbacks->add('inner');
                    });
                    $pdo->exec('INSERT INTO items VALUES (NULL)');
                });
            });

            self::assertTrue($pdo->inTransaction());
            self::assertSame([], $callbacks->all());
            $pdo->exec('INSERT INTO items VALUES (4)');
        });

        $result = $pdo->query('SELECT value FROM items ORDER BY value');
        self::assertNotFalse($result);
        self::assertSame([1, 4], $result->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(['outer'], $callbacks->all());
    }

    /** @param callable(): void $operation */
    private static function assertStorageFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('The failed transaction must propagate its storage exception.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString('NOT NULL constraint failed', $exception->getMessage());
        }
    }
}

final class TransactionCallbackLog
{
    /** @var list<string> */
    private array $calls = [];

    public function add(string $call): void
    {
        $this->calls[] = $call;
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->calls;
    }
}
