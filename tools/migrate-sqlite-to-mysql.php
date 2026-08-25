<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueueSchema;
use Register\Schema\SchemaManager;

const REGISTER_SQLITE_MYSQL_SKIPPED_TABLES = [
    'background_leases',
];

$rootDir = dirname(__DIR__);
require $rootDir . '/_vendor/autoload.php';
if (ini_set('memory_limit', '1024M') === false) {
    throw new RuntimeException('The database migration requires a PHP memory limit of at least 1 GB.');
}

$options = getopt('', ['apply', 'config:', 'help', 'source:', 'verify']);
if (!is_array($options)) {
    throw new RuntimeException('Unable to parse command-line options.');
}
if (isset($options['apply'], $options['verify'])) {
    throw new InvalidArgumentException('--apply and --verify are mutually exclusive.');
}
if (isset($options['help'])) {
    echo <<<'HELP'
Copies a current Register SQLite database into an already initialized MySQL/MariaDB database.

The target connection is read from config.local.php by default. Search tables and stale background
leases are cleared instead of copied; run tools/dev-bootstrap.php afterwards to rebuild search.

Usage:
  php tools/migrate-sqlite-to-mysql.php [--apply|--verify]
      [--source=.local/register.sqlite]
      [--config=config.local.php]

Without --apply the command only validates both databases and prints the planned row count.
Use --verify after migration to compare every copied value without changing either database.

HELP;
    exit(0);
}

/** Resolves a CLI path relative to the project root. */
$path = static function (mixed $value, string $default) use ($rootDir): string {
    $value = $value ?? $default;
    if (!is_string($value) || $value === '') {
        throw new InvalidArgumentException('Migration paths must be non-empty strings.');
    }

    return str_starts_with($value, '/') ? $value : $rootDir . '/' . $value;
};

$sourcePath = $path($options['source'] ?? null, '.local/register.sqlite');
$configPath = $path($options['config'] ?? null, 'config.local.php');
if (!is_file($sourcePath) || !is_readable($sourcePath)) {
    throw new RuntimeException('The source SQLite database is missing or unreadable: ' . $sourcePath);
}
if (!is_file($configPath) || !is_readable($configPath)) {
    throw new RuntimeException('The target config is missing or unreadable: ' . $configPath);
}

$config = require $configPath;
if (!is_array($config) || !is_array($config['database'] ?? null)) {
    throw new UnexpectedValueException('The target config has no database settings.');
}
$database = $config['database'];
if (($database['type'] ?? null) !== 'mysql') {
    throw new UnexpectedValueException('The migration target must use the mysql driver.');
}
foreach (['host', 'name', 'user', 'password'] as $key) {
    if (!is_string($database[$key] ?? null)) {
        throw new UnexpectedValueException('The target database setting is invalid: ' . $key . '.');
    }
}
if ($database['name'] === '') {
    throw new UnexpectedValueException('The target database name must not be empty.');
}

$source = new PDO('sqlite:' . $sourcePath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$source->exec('PRAGMA foreign_keys = ON');
$target = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $database['host'], $database['name']),
    $database['user'],
    $database['password'],
    [
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);

/** @return list<string> */
$sqliteTables = static function (PDO $pdo): array {
    $statement = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
    );
    if (!$statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to list source SQLite tables.');
    }

    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
};

/** @return list<string> */
$mysqlTables = static function (PDO $pdo): array {
    $statement = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
    );
    if (!$statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to list target MySQL tables.');
    }

    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
};

$quoteSqliteIdentifier = static function (string $identifier): string {
    if (preg_match('/^[a-z0-9_]+$/iD', $identifier) !== 1) {
        throw new UnexpectedValueException('Unsafe SQLite identifier: ' . $identifier);
    }

    return '"' . $identifier . '"';
};
$quoteMysqlIdentifier = static function (string $identifier): string {
    if (preg_match('/^[a-z0-9_]+$/iD', $identifier) !== 1) {
        throw new UnexpectedValueException('Unsafe MySQL identifier: ' . $identifier);
    }

    return '`' . $identifier . '`';
};

$fetchColumn = static function (PDO $pdo, string $sql): mixed {
    $statement = $pdo->query($sql);
    if (!$statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to execute scalar database query.');
    }

    return $statement->fetchColumn();
};

$sourceGeneration = $fetchColumn($source,
    "SELECT value FROM config WHERE name = 'REGISTER_SCHEMA_GENERATION'",
);
$targetGeneration = $fetchColumn($target,
    "SELECT value FROM config WHERE name = 'REGISTER_SCHEMA_GENERATION'",
);
foreach (['source' => $sourceGeneration, 'target' => $targetGeneration] as $label => $generation) {
    if ((string)$generation !== (string)SchemaManager::CURRENT_GENERATION) {
        throw new UnexpectedValueException(sprintf(
            'The %s database must use Register schema generation %d; found %s.',
            $label,
            SchemaManager::CURRENT_GENERATION,
            is_scalar($generation) ? (string)$generation : 'none',
        ));
    }
}

$sourceTables = $sqliteTables($source);
$targetTables = $mysqlTables($target);
$needsImportMap = in_array('e2_import_map', $sourceTables, true)
    && !in_array('e2_import_map', $targetTables, true);
if ($needsImportMap) {
    $targetTables[] = 'e2_import_map';
    sort($targetTables, SORT_STRING);
}

$isDerivedTable = static fn(string $table): bool => str_starts_with($table, 'register_search_idx_');
$copyTables = array_values(array_filter(
    array_intersect($sourceTables, $targetTables),
    static fn(string $table): bool => !$isDerivedTable($table)
        && !in_array($table, REGISTER_SQLITE_MYSQL_SKIPPED_TABLES, true),
));
sort($copyTables, SORT_STRING);

/** @return list<string> */
$sourceColumns = static function (PDO $pdo, string $table) use ($quoteSqliteIdentifier): array {
    $statement = $pdo->query('PRAGMA table_info(' . $quoteSqliteIdentifier($table) . ')');
    if (!$statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to inspect source table ' . $table . '.');
    }

    return array_map(
        static fn(array $row): string => (string)$row['name'],
        $statement->fetchAll(PDO::FETCH_ASSOC),
    );
};

/** @return list<string> */
$targetColumns = static function (PDO $pdo, string $table) use ($needsImportMap): array {
    if ($needsImportMap && $table === 'e2_import_map') {
        return ['entity_type', 'source_id', 'target_id', 'source_data'];
    }
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION',
    );
    if (!$statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to inspect target table ' . $table . '.');
    }
    $statement->execute(['table' => $table]);

    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
};

$sourceCounts = [];
$plannedRows = 0;
foreach ($copyTables as $table) {
    $columns = $sourceColumns($source, $table);
    $mysqlColumns = $targetColumns($target, $table);
    $missingFromTarget = array_diff($columns, $mysqlColumns);
    $missingFromSource = array_diff($mysqlColumns, $columns);
    if ($missingFromTarget !== [] || $missingFromSource !== []) {
        $targetMissingLabel = $missingFromTarget !== [] ? implode(', ', $missingFromTarget) : 'none';
        $sourceMissingLabel = $missingFromSource !== [] ? implode(', ', $missingFromSource) : 'none';
        throw new UnexpectedValueException(sprintf(
            'The table %s has different source/target columns (target missing: %s; source missing: %s).',
            $table,
            $targetMissingLabel,
            $sourceMissingLabel,
        ));
    }

    $count = (int)$fetchColumn($source,
        'SELECT COUNT(*) FROM ' . $quoteSqliteIdentifier($table),
    );
    $sourceCounts[$table] = $count;
    $plannedRows += $count;
}

/**
 * @param list<string> $columns
 * @param callable(string): string $quoteIdentifier
 */
$tableDigest = static function (
    PDO      $pdo,
    string   $table,
    array    $columns,
    callable $quoteIdentifier,
): string {
    $columnList = implode(', ', array_map($quoteIdentifier, $columns));
    $statement = $pdo->query(
        'SELECT ' . $columnList . ' FROM ' . $quoteIdentifier($table),
    );
    if (!$statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to verify table ' . $table . '.');
    }

    $rowHashes = [];
    while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
        $serialized = '';
        foreach ($row as $value) {
            if ($value === null) {
                $serialized .= 'N;';
                continue;
            }
            $value = (string)$value;
            $serialized .= 'S' . strlen($value) . ':' . $value . ';';
        }
        $rowHashes[] = hash('sha256', $serialized, true);
    }
    sort($rowHashes, SORT_STRING);

    return hash('sha256', implode('', $rowHashes));
};

$verifyCopiedTables = static function () use (
    $copyTables,
    $fetchColumn,
    $quoteMysqlIdentifier,
    $quoteSqliteIdentifier,
    $source,
    $sourceColumns,
    $sourceCounts,
    $tableDigest,
    $target,
): void {
    foreach ($copyTables as $table) {
        $expected = $sourceCounts[$table];
        $actual = (int)$fetchColumn($target,
            'SELECT COUNT(*) FROM ' . $quoteMysqlIdentifier($table),
        );
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'Copied table %s failed verification: expected %d rows, found %d.',
                $table,
                $expected,
                $actual,
            ));
        }

        $columns = $sourceColumns($source, $table);
        $sourceDigest = $tableDigest($source, $table, $columns, $quoteSqliteIdentifier);
        $targetDigest = $tableDigest($target, $table, $columns, $quoteMysqlIdentifier);
        if (!hash_equals($sourceDigest, $targetDigest)) {
            throw new RuntimeException('Copied table values failed verification: ' . $table . '.');
        }
    }
};

echo sprintf(
    "Validated generation %d: %d tables and %d stored rows will be copied into MySQL database %s.\n",
    SchemaManager::CURRENT_GENERATION,
    count($copyTables),
    $plannedRows,
    $database['name'],
);
echo "Search index tables and background leases will be rebuilt instead of copied.\n";
if (isset($options['verify'])) {
    $verifyCopiedTables();
    echo sprintf("Verification completed: %d copied rows match SQLite exactly.\n", $plannedRows);
    exit(0);
}
if (!isset($options['apply'])) {
    echo "Dry run: no target data was changed. Pass --apply to replace the initialized target data.\n";
    exit(0);
}

if ($needsImportMap) {
    $target->exec(
        'CREATE TABLE e2_import_map ('
        . 'entity_type VARCHAR(32) NOT NULL, '
        . 'source_id BIGINT NOT NULL, '
        . 'target_id INT UNSIGNED NOT NULL, '
        . 'source_data LONGTEXT NOT NULL, '
        . 'PRIMARY KEY (entity_type, source_id), '
        . 'KEY e2_import_map_target_idx (entity_type, target_id)'
        . ') ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    );
}
if (in_array('e2_import_map', $targetTables, true)) {
    // Telegram message IDs exceed 32 bits and a few synthetic tag IDs are negative.
    $target->exec('ALTER TABLE e2_import_map MODIFY source_id BIGINT NOT NULL');
}

$target->exec('SET FOREIGN_KEY_CHECKS = 0');
$target->beginTransaction();
try {
    foreach ($targetTables as $table) {
        $target->exec('DELETE FROM ' . $quoteMysqlIdentifier($table));
    }

    foreach ($copyTables as $table) {
        $columns = $sourceColumns($source, $table);
        $sqliteColumnList = implode(', ', array_map($quoteSqliteIdentifier, $columns));
        $mysqlColumnList = implode(', ', array_map($quoteMysqlIdentifier, $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $rows = $source->query(
            'SELECT ' . $sqliteColumnList . ' FROM ' . $quoteSqliteIdentifier($table),
        );
        if (!$rows instanceof PDOStatement) {
            throw new RuntimeException('Unable to read source table ' . $table . '.');
        }
        $insert = $target->prepare(
            'INSERT INTO ' . $quoteMysqlIdentifier($table)
            . ' (' . $mysqlColumnList . ') VALUES (' . $placeholders . ')',
        );
        if (!$insert instanceof PDOStatement) {
            throw new RuntimeException('Unable to prepare target insert for table ' . $table . '.');
        }
        while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
            $insert->execute($row);
        }
        echo sprintf("Copied %-36s %d\n", $table, $sourceCounts[$table]);
    }

    $target->commit();
} catch (Throwable $throwable) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    throw $throwable;
} finally {
    $target->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// A live lease must never be copied, but the target still requires its unlocked singleton row.
QueueSchema::ensureRunnerLease(new DbLayer($target));

$verifyCopiedTables();

echo sprintf("Migration completed: %d rows copied and value-verified.\n", $plannedRows);
echo "Run php tools/dev-bootstrap.php to rebuild the MySQL search index.\n";
