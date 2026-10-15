<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Pdo;

use Codeception\Test\Unit;
use Register\Core\Pdo\PDO;

final class PdoAfterCommitTest extends Unit
{
    public function testRunsCallbacksOnlyAfterCommit(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $calls = new PdoCallbackLog();

        $pdo->beginTransaction();
        $pdo->afterCommit(static function () use ($calls): void {
            $calls->add('committed');
        });

        self::assertSame([], $calls->all());
        $pdo->commit();
        self::assertSame(['committed'], $calls->all());
    }

    public function testDropsCallbacksOnRollback(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $calls = new PdoCallbackLog();

        $pdo->beginTransaction();
        $pdo->afterCommit(static function () use ($calls): void {
            $calls->add('rolled back');
        });
        $pdo->afterRollbackOnce('cleanup', static function () use ($calls): void {
            $calls->add('cleanup');
        });
        $pdo->rollBack();

        self::assertSame(['cleanup'], $calls->all());
    }

    public function testDropsOnlyCallbacksRegisteredInsideRolledBackSavepoint(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $calls = new PdoCallbackLog();

        $pdo->beginTransaction();
        $pdo->afterCommit(static function () use ($calls): void {
            $calls->add('outer');
        });
        $pdo->exec('SAVEPOINT cache_test');
        $pdo->afterCommit(static function () use ($calls): void {
            $calls->add('inner');
        });
        $pdo->afterRollbackOnce('inner-cleanup', static function () use ($calls): void {
            $calls->add('inner cleanup');
        });
        $pdo->exec('ROLLBACK TO SAVEPOINT cache_test');
        self::assertSame(['inner cleanup'], $calls->all());
        $pdo->exec('RELEASE SAVEPOINT cache_test');
        $pdo->commit();

        self::assertSame(['inner cleanup', 'outer'], $calls->all());
    }

    public function testRunsImmediatelyOutsideTransaction(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $called = false;

        $pdo->afterCommit(static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue($called);
    }

    public function testRollbackRunsUndoOperationsInReverseOrder(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $calls = new PdoCallbackLog();
        $pdo->beginTransaction();
        foreach (['first', 'second', 'third'] as $operation) {
            $pdo->afterRollback(static fn() => $calls->add($operation));
        }

        $pdo->rollBack();
        self::assertSame(['third', 'second', 'first'], $calls->all());
    }

    public function testNestedSavepointRollbacksRetainTheRemainingUndoOrder(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $calls = new PdoCallbackLog();
        $pdo->beginTransaction();
        $pdo->afterRollback(static fn() => $calls->add('outer'));
        $pdo->exec('SAVEPOINT media_first');
        $pdo->afterRollback(static fn() => $calls->add('first'));
        $pdo->exec('SAVEPOINT media_second');
        $pdo->afterRollback(static fn() => $calls->add('second'));
        $pdo->exec('ROLLBACK TO SAVEPOINT media_second');
        $pdo->exec('RELEASE SAVEPOINT media_second');
        self::assertSame(['second'], $calls->all());
        $pdo->afterRollback(static fn() => $calls->add('replacement'));
        $pdo->exec('RELEASE SAVEPOINT media_first');
        $pdo->rollBack();
        self::assertSame(['second', 'replacement', 'first', 'outer'], $calls->all());
    }

    public function testFailedCommitRetainsUndoUntilRollback(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE child (parent_id INTEGER REFERENCES parent(id) DEFERRABLE INITIALLY DEFERRED)');

        $calls = new PdoCallbackLog();
        $pdo->beginTransaction();
        $pdo->afterCommit(static fn() => $calls->add('commit'));
        $pdo->afterRollback(static fn() => $calls->add('undo'));
        $pdo->exec('INSERT INTO child VALUES (1)');
        try {
            $pdo->commit();
            self::fail('The deferred constraint must fail at commit.');
        } catch (\PDOException) {
            self::assertTrue($pdo->inTransaction());
            self::assertSame([], $calls->all());
        }

        $pdo->rollBack();
        self::assertSame(['undo'], $calls->all());
        $pdo->beginTransaction();
        $pdo->commit();
        self::assertSame(['undo'], $calls->all());
    }

    public function testCommitAndCallsOutsideATransactionDoNotRunUndo(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $calls = new PdoCallbackLog();
        $pdo->afterRollback(static fn() => $calls->add('outside'));
        $pdo->beginTransaction();
        $pdo->afterRollback(static fn() => $calls->add('committed'));
        $pdo->commit();
        $pdo->beginTransaction();
        $pdo->rollBack();
        self::assertSame([], $calls->all());
    }
}

final readonly class PdoCallbackLog
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $calls;

    public function __construct()
    {
        $this->calls = new \ArrayObject();
    }

    public function add(string $call): void
    {
        $this->calls->append($call);
    }

    /** @return list<string> */
    public function all(): array
    {
        $result = [];
        foreach ($this->calls as $call) {
            $result[] = $call;
        }

        return $result;
    }
}
