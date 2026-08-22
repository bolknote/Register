<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use S2\Cms\Pdo\DbLayer;

interface SchemaMigrationInterface
{
    public function fromGeneration(): int;

    public function toGeneration(): int;

    /** Implementations must be safe to retry after an interrupted request. */
    public function migrate(DbLayer $dbLayer): void;
}
