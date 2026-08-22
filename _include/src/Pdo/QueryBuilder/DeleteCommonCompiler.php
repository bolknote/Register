<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo\QueryBuilder;

readonly class DeleteCommonCompiler implements DeleteCompilerInterface
{
    public function __construct(private string $prefix)
    {
    }

    #[\Override]
    public function getSql(DeleteBuilder $builder): string
    {
        $sql = \sprintf('DELETE FROM %s%s', $this->prefix, $builder->getTable());

        $whereConditions = $builder->getWhere();
        if (\count($whereConditions) > 0) {
            $sql .= ' WHERE (' . implode(') AND (', $whereConditions). ')';
        }

        return $sql;
    }
}
