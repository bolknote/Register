<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo\QueryBuilder;

use Register\Core\Pdo\DbLayerException;

class UpsertBuilder
{
    use ParamsExecutableTrait;

    private ?string $table = null;

    /**
     * @var array<mixed>
     */
    private array $columnExpressions = [];

    /**
     * @var string[]
     */
    private array $uniqueColumns = [];

    public function __construct(
        private readonly UpsertCompilerInterface $compiler,
        private readonly QueryExecutorInterface  $queryExecutor,
    ) {
    }

    public function upsert(string $table): static
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
            throw new DbLayerException('No table to insert into has been specified.');
        }

        return $this->table;
    }

    /**
     * Specifies a column as a usual field, not a part of the unique key.
     * If there is a row that matches the unique key, the row will be updated.
     */
    public function setValue(string $column, string $expression): self
    {
        $this->columnExpressions[$column] = $expression;
        return $this;
    }

    /**
     * Specifies a column as a part of the unique key.
     */
    public function setKey(string $column, string $expression): self
    {
        $this->uniqueColumns[] = $column;
        return $this->setValue($column, $expression);
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getColumnExpressions(): array
    {
        if (\count($this->columnExpressions) === 0) {
            throw new DbLayerException('No fields to insert or update have been specified.');
        }

        return $this->columnExpressions;
    }

    /**
     * @return string[]
     */
    public function getUniqueColumns(): array
    {
        return $this->uniqueColumns;
    }
}
