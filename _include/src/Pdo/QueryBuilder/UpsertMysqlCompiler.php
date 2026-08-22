<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo\QueryBuilder;

readonly class UpsertMysqlCompiler implements UpsertCompilerInterface
{
    public function __construct(private string $prefix)
    {
    }

    #[\Override]
    public function getSql(UpsertBuilder $builder): string
    {
        $columnExpressions = $builder->getColumnExpressions();
        $tableName         = $this->prefix . $builder->getTable();
        $columnList        = implode(', ', array_keys($columnExpressions));
        $valuesList        = implode(', ', array_values($columnExpressions));
        $uniqueColumns     = $builder->getUniqueColumns();
        $updateList        = implode(
            ', ',
            array_map(static fn(string $columnName): string => "$columnName = VALUES($columnName)", array_diff(array_keys($columnExpressions), $uniqueColumns))
        );

        return $this->substituteQueryParts($tableName, $columnList, $valuesList, $updateList);
    }

    protected function substituteQueryParts(string $tableName, string $columnList, string $valuesList, string $updateList): string
    {
        /**
         * INSERT INTO table_name (column1, column2, ...)
         * VALUES (value1, value2, ...)
         * ON DUPLICATE KEY UPDATE column1 = VALUES(column1), column2 = VALUES(column2), ...;
         */
        return "INSERT INTO $tableName ($columnList) VALUES ($valuesList) ON DUPLICATE KEY UPDATE $updateList";
    }
}
