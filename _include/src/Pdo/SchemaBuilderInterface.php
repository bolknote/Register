<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Pdo;

interface SchemaBuilderInterface
{
    public const string TYPE_SERIAL = 'SERIAL';

    public const string TYPE_STRING = 'STRING';

    public const string TYPE_TEXT = 'TEXT';

    public const string TYPE_LONGTEXT = 'LONGTEXT';

    public const string TYPE_INTEGER = 'INTEGER';

    public const string TYPE_UNSIGNED_INTEGER = 'UNSIGNED INTEGER';

    public const string TYPE_BOOLEAN = 'BOOLEAN';

    public const string TYPE_FLOAT = 'FLOAT';

    public const string TYPE_DOUBLE = 'DOUBLE';

    public function addColumn(
        string               $name,
        string               $type,
        bool                 $nullable = false,
        string|int|bool|null $default = null,
        ?int                 $length = null
    ): self;

    public function addString(
        string  $name,
        int     $length = 255,
        bool    $nullable = false,
        ?string $default = '',
    ): self;

    public function addText(
        string $name,
        bool   $nullable = true,
    ): self;

    public function addLongText(
        string $name,
        bool   $nullable = true,
    ): self;

    public function addInteger(
        string $name,
        bool   $unsigned = false,
        bool   $nullable = false,
        ?int   $default = 0,
    ): self;

    public function addBoolean(
        string $name,
        bool   $nullable = false,
        bool   $default = false,
    ): self;

    public function addIdColumn(
        string $name = 'id'
    ): self;

    /**
     * @param list<string> $columns
     */
    public function setPrimaryKey(array $columns): self;

    /**
     * @param list<string> $columns
     */
    public function addUniqueIndex(string $indexName, array $columns): self;

    /**
     * @param list<string> $columns
     */
    public function addIndex(string $indexName, array $columns): self;

    /**
     * @param list<string> $columns
     * @param list<string> $foreignColumns
     */
    public function addForeignKey(
        string  $name,
        array   $columns,
        string  $foreignTable,
        array   $foreignColumns,
        ?string $onDelete = null,
        ?string $onUpdate = null,
    ): self;
}
