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
    /** @var list<string> */
    private array $calls = [];

    public function testRunsCallbacksOnlyAfterCommit(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $pdo->beginTransaction();
        $pdo->afterCommit(function (): void {
            $this->calls[] = 'committed';
        });

        self::assertSame([], $this->calls);
        $pdo->commit();
        self::assertSame(['committed'], $this->calls);
    }

    public function testDropsCallbacksOnRollback(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $pdo->beginTransaction();
        $pdo->afterCommit(function (): void {
            $this->calls[] = 'rolled back';
        });
        $pdo->afterRollbackOnce('cleanup', function (): void {
            $this->calls[] = 'cleanup';
        });
        $pdo->rollBack();

        self::assertSame(['cleanup'], $this->calls);
    }

    public function testDropsOnlyCallbacksRegisteredInsideRolledBackSavepoint(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $pdo->beginTransaction();
        $pdo->afterCommit(function (): void {
            $this->calls[] = 'outer';
        });
        $pdo->exec('SAVEPOINT cache_test');
        $pdo->afterCommit(function (): void {
            $this->calls[] = 'inner';
        });
        $pdo->afterRollbackOnce('inner-cleanup', function (): void {
            $this->calls[] = 'inner cleanup';
        });
        $pdo->exec('ROLLBACK TO SAVEPOINT cache_test');
        self::assertSame(['inner cleanup'], $this->calls);
        $pdo->exec('RELEASE SAVEPOINT cache_test');
        $pdo->commit();

        self::assertSame(['inner cleanup', 'outer'], $this->calls);
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
