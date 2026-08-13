<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Pdo;

/**
 * @phpstan-type ColumnDefinition array{type: string, nullable: bool, default: string|int|bool|null, length: int|null}
 * @phpstan-type ForeignKeyDefinition array{columns: list<string>, foreignTable: string, foreignColumns: list<string>, onDelete: string|null, onUpdate: string|null}
 */
class SchemaBuilder implements SchemaBuilderInterface
{
    public const string COLUMN_PROPERTY_TYPE = 'type';

    public const string COLUMN_PROPERTY_NULLABLE = 'nullable';

    public const string COLUMN_PROPERTY_DEFAULT = 'default';

    public const string COLUMN_PROPERTY_LENGTH = 'length';

    public const string FK_PROPERTY_COLUMNS = 'columns';

    public const string FK_PROPERTY_FOREIGN_TABLE = 'foreignTable';

    public const string FK_PROPERTY_FOREIGN_COLUMNS = 'foreignColumns';

    public const string FK_PROPERTY_ON_DELETE = 'onDelete';

    public const string FK_PROPERTY_ON_UPDATE = 'onUpdate';

    /** @var array<string, ColumnDefinition> */
    public array $columns = [];

    /** @var list<string> */
    public array $primaryKey = [];

    /** @var array<string, list<string>> */
    public array $uniqueIndexes = [];

    /** @var array<string, list<string>> */
    public array $indexes = [];

    /** @var array<string, ForeignKeyDefinition> */
    public array $foreignKeys = [];

    #[\Override]
    public function addIdColumn(string $name = 'id'): self
    {
        $this->addColumn($name, self::TYPE_SERIAL);
        $this->primaryKey = [$name];

        return $this;
    }

    #[\Override]
    public function addColumn(
        string               $name,
        string               $type,
        bool                 $nullable = false,
        string|int|bool|null $default = null,
        ?int                 $length = null,
    ): self {
        $this->columns[$name] = [
            self::COLUMN_PROPERTY_TYPE     => $type,
            self::COLUMN_PROPERTY_NULLABLE => $nullable,
            self::COLUMN_PROPERTY_DEFAULT  => $default,
            self::COLUMN_PROPERTY_LENGTH   => $length,
        ];
        return $this;
    }

    #[\Override]
    public function addString(string $name, int $length = 255, bool $nullable = false, ?string $default = ''): self
    {
        return $this->addColumn($name, self::TYPE_STRING, $nullable, $default, $length);
    }

    #[\Override]
    public function addText(string $name, bool $nullable = true): self
    {
        return $this->addColumn($name, self::TYPE_TEXT, $nullable);
    }

    #[\Override]
    public function addLongText(string $name, bool $nullable = true): self
    {
        return $this->addColumn($name, self::TYPE_LONGTEXT, $nullable);
    }

    #[\Override]
    public function addInteger(string $name, bool $unsigned = false, bool $nullable = false, ?int $default = 0): self
    {
        return $this->addColumn($name, $unsigned ? self::TYPE_UNSIGNED_INTEGER : self::TYPE_INTEGER, $nullable, $default);
    }

    #[\Override]
    public function addBoolean(string $name, bool $nullable = false, bool $default = false): self
    {
        return $this->addColumn($name, self::TYPE_BOOLEAN, $nullable, $default);
    }

    /**
     * @param list<string> $columns
     */
    #[\Override]
    public function setPrimaryKey(array $columns): self
    {
        $this->primaryKey = $columns;
        return $this;
    }

    /**
     * @param list<string> $columns
     */
    #[\Override]
    public function addUniqueIndex(string $indexName, array $columns): self
    {
        $this->uniqueIndexes[$indexName] = $columns;
        return $this;
    }

    /**
     * @param list<string> $columns
     */
    #[\Override]
    public function addIndex(string $indexName, array $columns): self
    {
        $this->indexes[$indexName] = $columns;
        return $this;
    }

    /**
     * @param list<string> $columns
     * @param list<string> $foreignColumns
     */
    #[\Override]
    public function addForeignKey(
        string  $name,
        array   $columns,
        string  $foreignTable,
        array   $foreignColumns,
        ?string $onDelete = null,
        ?string $onUpdate = null,
    ): self {
        $this->foreignKeys[$name] = [
            self::FK_PROPERTY_COLUMNS         => $columns,
            self::FK_PROPERTY_FOREIGN_TABLE   => $foreignTable,
            self::FK_PROPERTY_FOREIGN_COLUMNS => $foreignColumns,
            self::FK_PROPERTY_ON_DELETE       => $onDelete,
            self::FK_PROPERTY_ON_UPDATE       => $onUpdate,
        ];
        return $this;
    }
}
