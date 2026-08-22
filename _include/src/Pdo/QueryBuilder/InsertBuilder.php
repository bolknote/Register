<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo\QueryBuilder;

use Register\Core\Pdo\DbLayerException;

class InsertBuilder
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
        private readonly InsertCompilerInterface $compiler,
        private readonly QueryExecutorInterface  $queryExecutor,
    ) {
    }

    public function insert(string $table): static
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
     * @param array<mixed> $columnExpressions
     */
    public function values(array $columnExpressions): self
    {
        $this->columnExpressions = $columnExpressions;
        return $this;
    }

    public function setValue(string $column, string $expression): self
    {
        $this->columnExpressions[$column] = $expression;
        return $this;
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getColumnExpressions(): array
    {
        if (\count($this->columnExpressions) === 0) {
            throw new DbLayerException('No fields to insert have been specified.');
        }

        return $this->columnExpressions;
    }

    public function onConflictDoNothing(string ...$uniqueColumns): self
    {
        $this->uniqueColumns = $uniqueColumns;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getUniqueColumnsForConflictDoNothing(): array
    {
        return $this->uniqueColumns;
    }
}
