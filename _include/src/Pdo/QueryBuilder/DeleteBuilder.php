<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo\QueryBuilder;

use Register\Core\Pdo\DbLayerException;

class DeleteBuilder
{
    use ParamsExecutableTrait;
    use WhereTrait;

    private ?string $table = null;

    public function __construct(
        private readonly DeleteCompilerInterface $compiler,
        private readonly QueryExecutorInterface  $queryExecutor,
    ) {
    }

    public function delete(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * @throws DbLayerException
     */
    public function getTable(): string
    {
        if ($this->table === null) {
            throw new DbLayerException('No table to delete from has been specified.');
        }

        return $this->table;
    }
}
