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
    public function testCreatesPortableUtf8mb4Collations(): void
    {
        $charset = $this->createMock(\PDOStatement::class);
        $charset->method('fetchColumn')->willReturn('utf8mb4');

        $queries = [];
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('query')->willReturn($charset);
        $pdo->method('exec')->willReturnCallback(
            static function (string $statement) use (&$queries): int {
                $queries[] = $statement;

                return 0;
            },
        );

        (new MysqlRepository($pdo, 'prefix_', []))->erase();

        $createQueries = array_values(array_filter(
            $queries,
            static fn(string $query): bool => str_starts_with($query, 'CREATE TABLE'),
        ));
        self::assertCount(5, $createQueries);
        foreach (array_slice($createQueries, 0, 4) as $query) {
            self::assertStringContainsString('COLLATE utf8mb4_unicode_ci', $query);
        }
        self::assertStringContainsString('COLLATE utf8mb4_bin', $createQueries[4]);
    }

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
