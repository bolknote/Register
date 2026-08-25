<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueueSchema;

/** Restores the queue-runner singleton omitted by early SQLite-to-MySQL transfers. */
final readonly class QueueLeaseSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 21;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 22;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        QueueSchema::createRunnerLeaseStorage($dbLayer);
    }
}
