<?php

declare(strict_types = 1);

namespace unit\Cms\Pdo;

use PHPUnit\Framework\TestCase;
use Register\Core\Pdo\PDO;
use Register\Core\Pdo\PDOStatement;

final class LazyPdoTest extends TestCase
{
    public function testMetadataLogsAndTransactionChecksDoNotOpenTheDatabase(): void
    {
        $pdo = new PDO('sqlite:/nonexistent-register-test-directory/database.sqlite', lazy: true);
        self::assertFalse($pdo->isConnected());
        self::assertFalse($pdo->inTransaction());
        self::assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertSame([], $pdo->getQueryLog());
        $pdo->clearState();
        self::assertSame(0, $pdo->getQueryCount());
        self::assertFalse($pdo->isConnected());
    }

    public function testConnectsOnceAndConfiguresBeforeTheFirstStatement(): void
    {
        $pdo = new PDO('sqlite::memory:', lazy: true);
        $calls = new \ArrayObject();
        $pdo->addConnectionCallback(static function () use ($pdo, $calls): void {
            $calls->append('connected');
            $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)');
        });
        self::assertCount(0, $calls);
        $statement = $pdo->prepare('INSERT INTO test (value) VALUES (?)');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['test']);
        self::assertTrue($pdo->isConnected());
        self::assertSame('1', $pdo->lastInsertId());
        $result = $pdo->query('SELECT value FROM test', \PDO::FETCH_COLUMN, 0);
        self::assertInstanceOf(PDOStatement::class, $result);
        self::assertSame('test', $result->fetchColumn());
        self::assertCount(1, $calls);
        self::assertCount(1, array_filter($pdo->getQueryLog(), static fn(array $entry): bool => $entry['statement'] === 'PDO connect'));
    }

    public function testLazyTransactionsKeepCommitAndRollbackSemantics(): void
    {
        $pdo = new PDO('sqlite::memory:', lazy: true);
        self::assertTrue($pdo->beginTransaction());
        self::assertTrue($pdo->inTransaction());
        self::assertTrue($pdo->rollBack());
        self::assertFalse($pdo->inTransaction());
        self::assertTrue($pdo->beginTransaction());
        $committed = false;
        $pdo->afterCommit(static function () use (&$committed): void { $committed = true; });
        self::assertTrue($pdo->commit());
        self::assertTrue($committed);
    }

    public function testAttributesErrorsAndQuotingInitializeSafely(): void
    {
        $pdo = new PDO('sqlite::memory:', lazy: true);
        self::assertTrue($pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC));
        self::assertTrue($pdo->isConnected());
        self::assertSame(\PDO::FETCH_ASSOC, $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
        self::assertSame("'a''b'", $pdo->quote("a'b"));
        self::assertSame('00000', $pdo->errorCode());
        self::assertSame('00000', $pdo->errorInfo()[0]);
    }

    public function testConnectionFailuresAreDeferredUntilActualDatabaseWork(): void
    {
        $pdo = new PDO('sqlite:/nonexistent-register-test-directory/database.sqlite', lazy: true);
        $this->expectException(\PDOException::class);
        $pdo->query('SELECT 1');
    }

    public function testFailedConnectionSetupCannotBeBypassedByAnotherQuery(): void
    {
        $pdo = new PDO('sqlite::memory:', lazy: true);
        $failure = new \RuntimeException('Connection setup failed');
        $calls = new \ArrayObject();
        $pdo->addConnectionCallback(static function () use ($failure, $calls): never {
            $calls->append('setup');
            throw $failure;
        });
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $pdo->query('SELECT 1');
                self::fail('Queries must not run on an incompletely configured connection.');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
        }

        self::assertCount(1, $calls);
        self::assertCount(1, $pdo->getQueryLog(), 'Only the connection, not the SELECT, was performed.');
    }

    public function testEveryNativeInstanceMethodIsCoveredByTheWrapper(): void
    {
        foreach ((new \ReflectionClass(\PDO::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            self::assertSame(PDO::class, (new \ReflectionMethod(PDO::class, $method->getName()))->getDeclaringClass()->getName());
        }
    }
}
