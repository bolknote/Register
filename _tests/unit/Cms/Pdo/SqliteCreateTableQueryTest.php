<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Pdo;

use Codeception\Test\Unit;
use Register\Core\Pdo\SqliteCreateTableQuery;

final class SqliteCreateTableQueryTest extends Unit
{
    public function testParseSql(): void
    {
        $sql = "CREATE TABLE test_table (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            PRIMARY KEY (id),
            UNIQUE(name),
            CONSTRAINT fk_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
        );";

        $query = new SqliteCreateTableQuery($sql, []);

        self::assertSame('PRIMARY KEY (id)', $query->getPrimaryKey());
        self::assertEquals([
            'id'   => 'INTEGER PRIMARY KEY',
            'name' => 'TEXT NOT NULL'
        ], $query->getColumns());
        self::assertEquals(['UNIQUE(name)'], $query->getUnique());
        self::assertEquals([
            'fk_post' => 'CONSTRAINT fk_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE'
        ], $query->getForeignKeys());
    }

    public function testAddField(): void
    {
        $sql = "CREATE TABLE test_table (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        );";

        $query = new SqliteCreateTableQuery($sql, []);
        $query = $query->withNewField('description', 'TEXT', true, null, 'name');

        self::assertEquals([
            'id'          => 'INTEGER PRIMARY KEY',
            'name'        => 'TEXT NOT NULL',
            'description' => 'TEXT'
        ], $query->getColumns());

        $query = $query->withNewField('age', 'INTEGER', false, 0, 'id');

        self::assertEquals([
            'id'          => 'INTEGER PRIMARY KEY',
            'age'         => 'INTEGER NOT NULL DEFAULT 0',
            'name'        => 'TEXT NOT NULL',
            'description' => 'TEXT'
        ], $query->getColumns());
    }

    public function testAlterField(): void
    {
        $sql = "CREATE TABLE test_table (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            description TEXT DEFAULT ''
        );";

        $query = new SqliteCreateTableQuery($sql, []);
        $query = $query->withAlteredField('name', 'TEXT', false, 'Some Name', 'description');

        self::assertEquals([
            'id'          => 'INTEGER PRIMARY KEY',
            'description' => "TEXT DEFAULT ''",
            'name'        => "TEXT NOT NULL DEFAULT 'Some Name'",
        ], $query->getColumns());

        $query = $query->withAlteredField('description', 'TEXT', true, 'test');
        self::assertEquals([
            'id'          => 'INTEGER PRIMARY KEY',
            'description' => "TEXT DEFAULT 'test'",
            'name'        => "TEXT NOT NULL DEFAULT 'Some Name'",
        ], $query->getColumns());
    }

    public function testToString(): void
    {
        $sql = "CREATE TABLE test_table (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        );";

        $query = new SqliteCreateTableQuery($sql, []);
        $query = $query->withNewField('description', 'TEXT', true);

        $expectedSql = "CREATE TABLE test_table (
id INTEGER PRIMARY KEY,
name TEXT NOT NULL,
description TEXT
);";

        self::assertSame(trim($expectedSql), trim($query->__toString()));
    }

    public function testAddIndex(): void
    {
        $sql = "CREATE TABLE test_table (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        );";

        $query = new SqliteCreateTableQuery($sql, ['CREATE INDEX idx_name ON test_table(name)']);

        self::assertEquals(['CREATE INDEX idx_name ON test_table(name)'], $query->getIndexes());
    }
}
