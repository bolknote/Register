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
}

final class PdoCallbackLog
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
        return array_values($this->calls->getArrayCopy());
    }
}
