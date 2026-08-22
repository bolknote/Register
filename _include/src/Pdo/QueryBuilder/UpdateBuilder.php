<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo\QueryBuilder;

use Register\Core\Pdo\DbLayerException;

class UpdateBuilder
{
    use ParamsExecutableTrait;
    use JoinTrait;
    use WhereTrait;

    private ?string $table = null;

    /**
     * @var array<mixed>
     */
    private array $columnExpressions = [];

    public function __construct(
        private readonly UpdateCompilerInterface $compiler,
        private readonly QueryExecutorInterface  $queryExecutor,
    ) {
    }

    public function update(string $table): static
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
            throw new DbLayerException('No table to update has been specified.');
        }

        return $this->table;
    }

    public function set(string $column, string $expression): static
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
            throw new DbLayerException('No fields to update have been specified.');
        }

        return $this->columnExpressions;
    }
}
