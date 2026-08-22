<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 */

declare(strict_types = 1);

namespace Register\Rose\Test\Storage\Database;

use Codeception\Test\Unit;
use Register\Rose\Storage\Database\MysqlRepository;

final class MysqlRepositoryTest extends Unit
{
    public function testInsertWordsUsesPreparedStatements(): void
    {
        $capturedSql   = '';
        $executedParams = [];

        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturnCallback(
            static function (?array $params = null) use (&$executedParams): bool {
                $executedParams[] = $params ?? [];

                return true;
            }
        );

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            static function (string $statementSql) use (&$capturedSql, $statement): \PDOStatement {
                $capturedSql = $statementSql;

                return $statement;
            }
        );

        $repository = new MysqlRepository($pdo, 'prefix_', []);
        $repository->insertWords(['test"', "danger\\word"]);

        self::assertStringNotContainsString('test"', $capturedSql);
        self::assertSame([['test"', "danger\\word"]], $executedParams);
    }
}
