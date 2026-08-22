<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Schema\SchemaMigrationInterface;
use Register\Schema\SchemaMigrator;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerSqlite;

final class SchemaMigratorTest extends Unit
{
    public function testRunsAndRecordsEachGenerationInOrder(): void
    {
        $dbLayer   = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        $calls     = new SchemaMigrationCallLog();
        $migrator  = new SchemaMigrator($dbLayer, [
            new RecordingSchemaMigration(15, 16, $calls),
            new RecordingSchemaMigration(16, 17, $calls),
        ]);
        $stored = [];

        self::assertTrue($migrator->migrate(15, 17, static function (int $generation) use (&$stored): void {
            $stored[] = $generation;
        }));
        self::assertSame(['15-16', '16-17'], $calls->all());
        self::assertSame([16, 17], $stored);
    }

    public function testRejectsMissingMigrationStep(): void
    {
        $dbLayer = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no schema migration from generation 15 to 16');
        (new SchemaMigrator($dbLayer, []))->migrate(15, 16, static function (int $_generation): void {
        });
    }
}

/** @internal */
final readonly class RecordingSchemaMigration implements SchemaMigrationInterface
{
    public function __construct(
        private int                    $from,
        private int                    $to,
        private SchemaMigrationCallLog $calls,
    ) {
    }

    #[\Override]
    public function fromGeneration(): int
    {
        return $this->from;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return $this->to;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        $this->calls->add($this->from . '-' . $this->to);
    }
}

/** @internal */
final class SchemaMigrationCallLog
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
