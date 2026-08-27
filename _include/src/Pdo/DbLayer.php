<?php /** @noinspection SqlDialectInspection */
/**
 * A database abstract layer class.
 * Contains default implementation for MySQL database.
 *
 * @copyright 2009-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo;

use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Pdo\QueryBuilder\DeleteBuilder;
use Register\Core\Pdo\QueryBuilder\DeleteCommonCompiler;
use Register\Core\Pdo\QueryBuilder\InsertBuilder;
use Register\Core\Pdo\QueryBuilder\InsertMysqlCompiler;
use Register\Core\Pdo\QueryBuilder\SelectBuilder;
use Register\Core\Pdo\QueryBuilder\SelectCommonCompiler;
use Register\Core\Pdo\QueryBuilder\UnionAll;
use Register\Core\Pdo\QueryBuilder\UpdateBuilder;
use Register\Core\Pdo\QueryBuilder\UpdateCommonCompiler;
use Register\Core\Pdo\QueryBuilder\UpsertBuilder;
use Register\Core\Pdo\QueryBuilder\UpsertMysqlCompiler;

class DbLayer implements QueryBuilder\QueryExecutorInterface, StatefulServiceInterface
{
    protected int $transactionLevel = 0;

    /** @var array<string, bool> */
    private array $tableExistenceCache = [];

    public function __construct(
        protected \PDO   $pdo,
        protected string $prefix = ''
    ) {
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    #[\Override]
    public function clearState(): void
    {
        $this->tableExistenceCache = [];
    }

    public function startTransaction(): void
    {
        ++$this->transactionLevel;
        $this->pdo->beginTransaction();
    }

    public function endTransaction(): void
    {
        if ($this->transactionLevel > 0) {
            --$this->transactionLevel;
            $this->pdo->commit();
        }
    }

    /**
     * @throws DbLayerException
     * @param array<int|string, mixed> $params
     * @param array<int|string, int> $types
     */
    #[\Override]
    public function query(string $sql, array $params = [], array $types = []): QueryResult
    {
        $stmt = $this->pdo->prepare($sql);
        if (!$stmt instanceof \PDOStatement) {
            throw new DbLayerException('Unable to prepare query: ' . $sql, 0, $sql);
        }

        try {
            if ($types !== []) {
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value, $types[$key] ?? \PDO::PARAM_STR);
                }

                $stmt->execute();
            } else {
                $stmt->execute($params);
            }

            return new QueryResult($stmt);
        } catch (\PDOException $pdoException) {
            if ($this->transactionLevel > 0) {
                try {
                    $this->pdo->rollBack();
                } catch (\PDOException $pdoException) {
                    throw new DbLayerException('An exception occurred on rollback: ' . $pdoException->getMessage(), 0, $sql, $pdoException->getPrevious());
                }

                --$this->transactionLevel;
            }

            throw new DbLayerException(
                \sprintf("%s. Failed query: %s. Error code: %s.", $pdoException->getMessage(), $sql, $pdoException->getCode()),
                $pdoException->errorInfo[1] ?? 0,
                $sql,
                $pdoException
            );
        }
    }

    public function insertId(): false|string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * @throws DbLayerException
     * @return array<string, mixed>
     */
    public function getVersion(): array
    {
        $result = $this->select('VERSION()')->execute();

        return [
            'name'    => 'MySQL',
            'version' => $result->result(),
        ];
    }

    /**
     * @throws DbLayerException
     */
    public function tableExists(string $tableName): bool
    {
        $cached = $this->cachedTableExists($tableName);
        if ($cached !== null) {
            return $cached;
        }

        $sql    = 'SHOW TABLES LIKE ' . $this->pdo->quote($this->prefix . $tableName);
        $result = $this->query($sql);

        return $this->rememberTableExists($tableName, \count($result->fetchAssocAll()) > 0);
    }

    /**
     * @throws DbLayerException
     */
    public function fieldExists(string $tableName, string $fieldName): bool
    {
        $sql    = 'SHOW COLUMNS FROM `' . $this->prefix . $tableName . '` LIKE ' . $this->pdo->quote($fieldName);
        $result = $this->query($sql);

        return \count($result->fetchAssocAll()) > 0;
    }


    /**
     * @throws DbLayerException
     */
    public function indexExists(string $tableName, string $indexName): bool
    {
        $result = $this->query('SHOW INDEX FROM ' . $this->prefix . $tableName);
        while ($currentIndex = $result->fetchAssoc()) {
            if (strtolower($currentIndex['Key_name']) === strtolower($this->prefix . $tableName . '_' . $indexName)) {
                return true;
            }
        }

        return false;
    }


    /**
     * @throws DbLayerException
     */
    public function createTable(string $tableName, callable $tableDefinition): void
    {
        $this->forgetTableExists($tableName);
        if ($this->tableExists($tableName)) {
            return;
        }

        $schemaBuilder = new SchemaBuilder();
        $tableDefinition($schemaBuilder);

        $query = 'CREATE TABLE ' . $this->prefix . $tableName . " (\n";

        // Go through every schema element and add it to the query
        foreach ($schemaBuilder->columns as $fieldName => $fieldData) {
            $type = static::convertType($fieldData[SchemaBuilder::COLUMN_PROPERTY_TYPE], $fieldData[SchemaBuilder::COLUMN_PROPERTY_LENGTH]);

            $query .= $fieldName . ' ' . $type;

            if (!$fieldData[SchemaBuilder::COLUMN_PROPERTY_NULLABLE]) {
                $query .= ' NOT NULL';
            } elseif (!isset($fieldData[SchemaBuilder::COLUMN_PROPERTY_DEFAULT])) {
                $query .= ' DEFAULT NULL';
            }

            if (isset($fieldData[SchemaBuilder::COLUMN_PROPERTY_DEFAULT])) {
                $defaultValue = self::convertDefaultValue($fieldData[SchemaBuilder::COLUMN_PROPERTY_DEFAULT], $fieldData[SchemaBuilder::COLUMN_PROPERTY_TYPE]);
                $query .= ' DEFAULT ' . $this->formatDefaultValue($defaultValue);
            }

            $query .= ",\n";
        }

        // If we have a primary key, add it
        if (\count($schemaBuilder->primaryKey) > 0) {
            $query .= 'PRIMARY KEY (' . implode(',', $schemaBuilder->primaryKey) . '),' . "\n";
        }

        // Add unique keys
        foreach ($schemaBuilder->uniqueIndexes as $keyName => $keyFields) {
            $query .= 'UNIQUE KEY ' . $this->prefix . $tableName . '_' . $keyName . '(' . implode(',', $keyFields) . '),' . "\n";
        }

        // Add indexes
        foreach ($schemaBuilder->indexes as $index_name => $index_fields) {
            $query .= 'KEY ' . $this->prefix . $tableName . '_' . $index_name . '(' . implode(',', $index_fields) . '),' . "\n";
        }

        // We remove the last two characters (a newline and a comma) and add on the ending
        // Keep dumps portable between supported MySQL and MariaDB versions. New MariaDB releases
        // otherwise choose their `utf8mb4_uca1400_ai_ci` default for an explicit character set;
        // MySQL cannot restore a dump containing that MariaDB-only collation.
        $query = substr($query, 0, -2) . "\n"
            . ') ENGINE = InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        $this->query($query);
        $this->rememberTableExists($tableName, true);

        // Add foreign keys
        foreach ($schemaBuilder->foreignKeys as $keyName => $foreignKey) {
            $this->addForeignKey(
                $tableName,
                $keyName,
                $foreignKey[SchemaBuilder::FK_PROPERTY_COLUMNS],
                $foreignKey[SchemaBuilder::FK_PROPERTY_FOREIGN_TABLE],
                $foreignKey[SchemaBuilder::FK_PROPERTY_FOREIGN_COLUMNS],
                $foreignKey[SchemaBuilder::FK_PROPERTY_ON_DELETE],
                $foreignKey[SchemaBuilder::FK_PROPERTY_ON_UPDATE],
            );
        }
    }

    /**
     * @throws DbLayerException
     */
    public function dropTable(string $tableName): void
    {
        $this->forgetTableExists($tableName);
        if (!$this->tableExists($tableName)) {
            return;
        }

        $this->query('DROP TABLE ' . $this->prefix . $tableName);
        $this->rememberTableExists($tableName, false);
    }

    protected function cachedTableExists(string $tableName): ?bool
    {
        $qualifiedName = $this->prefix . $tableName;

        return $this->tableExistenceCache[$qualifiedName] ?? null;
    }

    protected function rememberTableExists(string $tableName, bool $exists): bool
    {
        $this->tableExistenceCache[$this->prefix . $tableName] = $exists;

        return $exists;
    }

    protected function forgetTableExists(string $tableName): void
    {
        unset($this->tableExistenceCache[$this->prefix . $tableName]);
    }


    /**
     * @throws DbLayerException
     */
    public function addField(
        string                     $tableName,
        string                     $fieldName,
        string                     $fieldType,
        ?int                       $fieldLength,
        bool                       $allowNull,
        string|int|float|bool|null $defaultValue = null,
        ?string                    $afterField = null
    ): void {
        if ($this->fieldExists($tableName, $fieldName)) {
            return;
        }

        $fieldType = self::convertType($fieldType, $fieldLength);

        $defaultClause = '';
        if ($defaultValue !== null) {
            $defaultClause = ' DEFAULT ' . $this->formatDefaultValue($defaultValue);
        }

        $this->query(\sprintf(
            'ALTER TABLE %s ADD %s %s%s%s%s',
            $this->prefix . $tableName,
            $fieldName,
            $fieldType,
            $allowNull ? '' : ' NOT NULL',
            $defaultClause,
            $afterField !== null ? ' AFTER ' . $afterField : ''
        ));
    }

    /**
     * @throws DbLayerException
     */
    public function renameField(string $tableName, string $oldFieldName, string $newFieldName): void
    {
        $this->query('ALTER TABLE ' . $this->prefix . $tableName . ' RENAME COLUMN ' . $oldFieldName . ' TO ' . $newFieldName);
    }

    /**
     * @throws DbLayerException
     */
    public function alterField(
        string                     $tableName,
        string                     $fieldName,
        string                     $fieldType,
        ?int                       $fieldLength,
        bool                       $allowNull,
        string|int|float|bool|null $defaultValue = null,
        ?string                    $afterField = null
    ): void {
        if (!$this->fieldExists($tableName, $fieldName)) {
            return;
        }

        $fieldType = self::convertType($fieldType, $fieldLength);

        $defaultClause = '';
        if ($defaultValue !== null) {
            $defaultClause = ' DEFAULT ' . $this->formatDefaultValue($defaultValue);
        }

        $this->query(\sprintf(
            'ALTER TABLE %s MODIFY %s %s%s%s%s',
            $this->prefix . $tableName,
            $fieldName,
            $fieldType,
            $allowNull ? '' : ' NOT NULL',
            $defaultClause,
            $afterField !== null ? ' AFTER ' . $afterField : ''
        ));
    }


    /**
     * @throws DbLayerException
     */
    public function dropField(string $tableName, string $fieldName): void
    {
        if (!$this->tableExists($tableName) || !$this->fieldExists($tableName, $fieldName)) {
            return;
        }

        $this->query('ALTER TABLE ' . $this->prefix . $tableName . ' DROP ' . $fieldName);
    }


    /**
     * @throws DbLayerException
     * @param list<string> $indexFields
     */
    public function addIndex(string $tableName, string $indexName, array $indexFields, bool $unique = false): void
    {
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        $this->query('ALTER TABLE ' . $this->prefix . $tableName . ' ADD ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->prefix . $tableName . '_' . $indexName . ' (' . implode(',', $indexFields) . ')');
    }


    /**
     * @throws DbLayerException
     */
    public function dropIndex(string $tableName, string $indexName): void
    {
        if (!$this->indexExists($tableName, $indexName)) {
            return;
        }

        $this->query('ALTER TABLE ' . $this->prefix . $tableName . ' DROP INDEX ' . $this->prefix . $tableName . '_' . $indexName);
    }

    /**
     * @throws DbLayerException
     */
    public function foreignKeyExists(string $tableName, string $fkName): bool
    {
        $tableNameWithPrefix = $this->prefix . $tableName;

        // Query to check if the foreign key exists
        $sql = 'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND CONSTRAINT_NAME = :foreign_key_name
                AND REFERENCED_TABLE_NAME IS NOT NULL';

        $result = $this->query($sql, [
            'table_name'       => $tableNameWithPrefix,
            'foreign_key_name' => $tableNameWithPrefix . '_' . $fkName
        ]);

        return (bool)$result->result();
    }

    /**
     * @throws DbLayerException
     * @param string[] $columns
     * @param string[] $referenceColumns
     */
    public function addForeignKey(string $tableName, string $fkName, array $columns, string $referenceTable, array $referenceColumns, ?string $onDelete = null, ?string $onUpdate = null): void
    {
        if ($this->foreignKeyExists($tableName, $fkName)) {
            return;
        }

        $tableNameWithPrefix = $this->prefix . $tableName;

        $query = 'ALTER TABLE ' . $tableNameWithPrefix . ' ADD CONSTRAINT ' . $tableNameWithPrefix . '_' . $fkName .
            ' FOREIGN KEY (' . implode(',', $columns) . ')' .
            ' REFERENCES ' . $this->prefix . $referenceTable . ' (' . implode(',', $referenceColumns) . ')';

        if ($onDelete !== null) {
            $query .= ' ON DELETE ' . $onDelete;
        }

        if ($onUpdate !== null) {
            $query .= ' ON UPDATE ' . $onUpdate;
        }

        $this->query($query);
    }

    /**
     * @throws DbLayerException
     */
    public function dropForeignKey(string $tableName, string $fkName): void
    {
        if (!$this->foreignKeyExists($tableName, $fkName)) {
            return;
        }

        $tableNameWithPrefix = $this->prefix . $tableName;

        $query = 'ALTER TABLE ' . $tableNameWithPrefix . ' DROP FOREIGN KEY ' . $tableNameWithPrefix . '_' . $fkName;

        $this->query($query);
    }

    public function select(string ...$expressions): SelectBuilder
    {
        return (new SelectBuilder(new SelectCommonCompiler($this->prefix), $this))->select(...$expressions);
    }

    public function withRecursive(string $name, UnionAll|SelectBuilder $param): SelectBuilder
    {
        return (new SelectBuilder(new SelectCommonCompiler($this->prefix), $this))->withRecursive($name, $param);
    }

    public function update(string $table): UpdateBuilder
    {
        return (new UpdateBuilder(new UpdateCommonCompiler($this->prefix), $this))->update($table);
    }

    public function insert(string $table): InsertBuilder
    {
        return (new InsertBuilder(new InsertMysqlCompiler($this->prefix), $this))->insert($table);
    }

    public function delete(string $table): DeleteBuilder
    {
        return (new DeleteBuilder(new DeleteCommonCompiler($this->prefix), $this))->delete($table);
    }

    public function upsert(string $table): UpsertBuilder
    {
        return (new UpsertBuilder(new UpsertMysqlCompiler($this->prefix), $this))->upsert($table);
    }

    protected static function convertType(string $type, ?int $length): string
    {
        return match ($type) {
            SchemaBuilderInterface::TYPE_SERIAL => 'INT(10) UNSIGNED AUTO_INCREMENT',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER => 'INT(10) UNSIGNED',
            SchemaBuilderInterface::TYPE_INTEGER => 'INT(11)',
            SchemaBuilderInterface::TYPE_BOOLEAN => 'TINYINT(1)',
            SchemaBuilderInterface::TYPE_LONGTEXT => 'LONGTEXT',
            SchemaBuilderInterface::TYPE_TEXT => 'TEXT',
            SchemaBuilderInterface::TYPE_STRING => 'VARCHAR(' . ($length ?? 255) . ')',
            default => $type
        };
    }

    protected static function convertDefaultValue(string|int|bool $value, string $type): string|int
    {
        return match ($type) {
            SchemaBuilderInterface::TYPE_SERIAL => throw new \InvalidArgumentException('SERIAL type cannot have a default value'),
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            SchemaBuilderInterface::TYPE_BOOLEAN,
            SchemaBuilderInterface::TYPE_INTEGER => (int)$value,
            default => (string)$value
        };
    }

    protected function formatDefaultValue(string|int|float|bool $value): string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string)$value;
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        $quoted = $this->pdo->quote($value);
        if ($quoted === false) {
            throw new \RuntimeException('Unable to quote a database default value.');
        }

        return $quoted;
    }
}
