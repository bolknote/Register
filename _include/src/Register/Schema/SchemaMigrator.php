<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use S2\Cms\Pdo\DbLayer;

final readonly class SchemaMigrator
{
    /** @var array<int, SchemaMigrationInterface> */
    private array $migrations;

    /** @param list<SchemaMigrationInterface> $migrations */
    public function __construct(
        private DbLayer $dbLayer,
        array           $migrations,
    ) {
        $byGeneration = [];
        foreach ($migrations as $migration) {
            $from = $migration->fromGeneration();
            $to   = $migration->toGeneration();
            if ($from < 1 || $to !== $from + 1 || isset($byGeneration[$from])) {
                throw new \LogicException('Register schema migrations must form unique one-generation steps.');
            }

            $byGeneration[$from] = $migration;
        }

        $this->migrations = $byGeneration;
    }

    /** @param callable(int): void $storeGeneration */
    public function migrate(int $currentGeneration, int $targetGeneration, callable $storeGeneration): bool
    {
        if ($targetGeneration < $currentGeneration) {
            throw new \LogicException('Register does not support schema downgrades.');
        }

        $changed = false;
        while ($currentGeneration < $targetGeneration) {
            $migration = $this->migrations[$currentGeneration] ?? null;
            if (!$migration instanceof SchemaMigrationInterface) {
                throw new \LogicException(sprintf(
                    'Register has no schema migration from generation %d to %d.',
                    $currentGeneration,
                    $currentGeneration + 1,
                ));
            }

            $migration->migrate($this->dbLayer);
            $currentGeneration = $migration->toGeneration();
            $storeGeneration($currentGeneration);
            $changed = true;
        }

        return $changed;
    }
}
