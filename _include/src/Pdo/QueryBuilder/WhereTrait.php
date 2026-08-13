<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Pdo\QueryBuilder;

trait WhereTrait
{
    /** @var list<string> */
    private array $where = [];

    public function where(string $condition): self
    {
        $this->where = [];
        return $this->andWhere($condition);
    }

    public function andWhere(string $condition): self
    {
        $this->where[] = $condition;
        return $this;
    }

    /** @return list<string> */
    public function getWhere(): array
    {
        return $this->where;
    }
}
