<?php
/**
 * Transfers completed link-health probes between installations without replacing content data.
 *
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const REGISTER_LINK_HEALTH_TRANSFER_FORMAT = 'register-link-health';
const REGISTER_LINK_HEALTH_TRANSFER_VERSION = 1;

$rootDir = dirname(__DIR__);
$options = getopt('', ['apply', 'export:', 'help', 'import:', 'source:', 'source-config:']);
if (!\is_array($options)) {
    throw new RuntimeException('Unable to parse command-line options.');
}
if (isset($options['help'])) {
    echo <<<'HELP'
Exports completed external-link probes from a local conversion database and applies them to another
Register installation. Content, comments and link inventory are never replaced. Targets are matched
by their URL hash, and the import aborts before writing if identities differ.

Export:
  php tools/transfer-link-health.php --source=.local/register.sqlite --export=/path/link-health.json
  php tools/transfer-link-health.php --source-config=config.local.php --export=/path/link-health.json

Validate or apply on the destination installation:
  php tools/transfer-link-health.php --import=/path/link-health.json
  php tools/transfer-link-health.php --import=/path/link-health.json --apply

The export refuses to run while register_link_check jobs remain. Finish the offline probe queue first.

HELP;
    exit(0);
}

$path = static function (mixed $value): string {
    if (!\is_string($value) || $value === '') {
        throw new InvalidArgumentException('Transfer paths must be non-empty strings.');
    }

    return str_starts_with($value, '/') ? $value : dirname(__DIR__) . '/' . $value;
};

$exportPath = isset($options['export']) ? $path($options['export']) : null;
$importPath = isset($options['import']) ? $path($options['import']) : null;
if (($exportPath === null) === ($importPath === null)) {
    fwrite(STDERR, "Choose exactly one of --export or --import. Use --help for examples.\n");
    exit(2);
}
$hasSourcePath = isset($options['source']);
$hasSourceConfig = isset($options['source-config']);
if (($exportPath === null && ($hasSourcePath || $hasSourceConfig)) || ($hasSourcePath && $hasSourceConfig)) {
    fwrite(STDERR, "Choose either --source or --source-config, and use it only with --export.\n");
    exit(2);
}

/** @return \PDOStatement */
$query = static function (\PDO $pdo, string $sql, array $params = []): \PDOStatement {
    $statement = $pdo->prepare($sql);
    if (!$statement instanceof \PDOStatement) {
        throw new RuntimeException('Unable to prepare a link-health transfer query.');
    }
    $statement->execute($params);

    return $statement;
};

/** @param list<int> $ids */
$deleteByTargetIds = static function (\PDO $pdo, string $table, array $ids) use ($query): int {
    $deleted = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $statement = $query($pdo, 'DELETE FROM ' . $table . ' WHERE target_id IN (' . $placeholders . ')', $chunk);
        $deleted += $statement->rowCount();
    }

    return $deleted;
};

if ($exportPath !== null) {
    $sourcePrefix = '';
    if ($hasSourceConfig) {
        $sourceConfigPath = $path($options['source-config']);
        if (!is_file($sourceConfigPath) || !is_readable($sourceConfigPath)) {
            throw new RuntimeException('The source configuration is missing or unreadable: ' . $sourceConfigPath);
        }
        $sourceConfig = require $sourceConfigPath;
        $database = \is_array($sourceConfig) ? ($sourceConfig['database'] ?? null) : null;
        if (!\is_array($database)) {
            throw new UnexpectedValueException('The source database configuration is invalid.');
        }
        $sourcePrefix = (string)($database['prefix'] ?? '');
        if (preg_match('/^[a-z0-9_]*$/iD', $sourcePrefix) !== 1) {
            throw new UnexpectedValueException('The source database prefix is unsafe.');
        }
        $databaseType = (string)($database['type'] ?? '');
        $source = match ($databaseType) {
            'mysql' => new \PDO(
                sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    (string)($database['host'] ?? ''),
                    (string)($database['name'] ?? ''),
                ),
                (string)($database['user'] ?? ''),
                (string)($database['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC],
            ),
            'sqlite' => new \PDO(
                'sqlite:' . $path($database['name'] ?? ''),
                null,
                null,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC],
            ),
            default => throw new UnexpectedValueException('The configured source database type is unsupported.'),
        };
    } else {
        $sourcePath = $path($options['source'] ?? '.local/register.sqlite');
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('The source SQLite database is missing or unreadable: ' . $sourcePath);
        }

        $source = new \PDO('sqlite:' . $sourcePath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }
    $sourceQueueTable = $sourcePrefix . 'queue';
    $sourceTargetTable = $sourcePrefix . 'register_link_target';
    $sourceCheckTable = $sourcePrefix . 'register_link_check';
    $pending = (int)$query(
        $source,
        "SELECT COUNT(*) FROM {$sourceQueueTable} WHERE code = 'register_link_check' AND failed_at IS NULL",
    )->fetchColumn();
    if ($pending !== 0) {
        throw new RuntimeException(sprintf(
            'Offline link probing is incomplete: %d register_link_check jobs remain.',
            $pending,
        ));
    }

    $targets = $query($source, <<<SQL
        SELECT url_hash, normalized_url, kind, health_status, http_status, failure_count,
            effective_url, last_error, last_checked_at, last_success_at, next_check_at,
            archive_status, archive_url, archive_timestamp, archive_checked_at, archive_lookup_token
        FROM {$sourceTargetTable}
        WHERE kind = 'external'
        ORDER BY url_hash
        SQL)->fetchAll(\PDO::FETCH_ASSOC);
    $checks = $query($source, <<<SQL
        SELECT target.url_hash AS target_hash, check_row.probe_token, check_row.checked_at,
            check_row.health_status, check_row.http_status, check_row.effective_url, check_row.error
        FROM {$sourceCheckTable} AS check_row
        INNER JOIN {$sourceTargetTable} AS target ON target.id = check_row.target_id
        WHERE target.kind = 'external'
        ORDER BY check_row.checked_at, check_row.id
        SQL)->fetchAll(\PDO::FETCH_ASSOC);

    $snapshot = [
        'format' => REGISTER_LINK_HEALTH_TRANSFER_FORMAT,
        'version' => REGISTER_LINK_HEALTH_TRANSFER_VERSION,
        'generated_at' => gmdate(DATE_ATOM),
        'targets' => $targets,
        'checks' => $checks,
    ];
    $json = json_encode(
        $snapshot,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $directory = dirname($exportPath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the snapshot directory.');
    }
    if (file_put_contents($exportPath, $json . "\n") === false) {
        throw new RuntimeException('Unable to write the link-health snapshot.');
    }
    chmod($exportPath, 0600);

    echo json_encode([
        'snapshot' => $exportPath,
        'targets' => count($targets),
        'checks' => count($checks),
        'pending_checks' => 0,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if ($importPath === null) {
    throw new LogicException('The import path was not selected.');
}
if (!is_file($importPath) || !is_readable($importPath)) {
    throw new RuntimeException('The link-health snapshot is missing or unreadable: ' . $importPath);
}
$contents = file_get_contents($importPath);
if (!\is_string($contents)) {
    throw new RuntimeException('Unable to read the link-health snapshot.');
}
$snapshot = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
if (!\is_array($snapshot)
    || ($snapshot['format'] ?? null) !== REGISTER_LINK_HEALTH_TRANSFER_FORMAT
    || ($snapshot['version'] ?? null) !== REGISTER_LINK_HEALTH_TRANSFER_VERSION
    || !\is_array($snapshot['targets'] ?? null)
    || !\is_array($snapshot['checks'] ?? null)
) {
    throw new UnexpectedValueException('The link-health snapshot format is unsupported.');
}

$app = require $rootDir . '/_include/common.php';
$pdo = $app->container->get(\PDO::class);
if (!$pdo instanceof \PDO) {
    throw new UnexpectedValueException('The application did not provide a PDO connection.');
}
$prefix = $app->container->getStringParameter('db_prefix');
if (preg_match('/^[a-z0-9_]*$/iD', $prefix) !== 1) {
    throw new UnexpectedValueException('The destination database prefix is unsafe.');
}
$targetTable = $prefix . 'register_link_target';
$checkTable = $prefix . 'register_link_check';
$queueTable = $prefix . 'queue';

$sourceTargets = [];
foreach ($snapshot['targets'] as $target) {
    if (!\is_array($target)
        || !\is_string($target['url_hash'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/D', $target['url_hash']) !== 1
        || !\is_string($target['normalized_url'] ?? null)
        || ($target['kind'] ?? null) !== 'external'
    ) {
        throw new UnexpectedValueException('The snapshot contains an invalid link target.');
    }
    if (isset($sourceTargets[$target['url_hash']])) {
        throw new UnexpectedValueException('The snapshot contains a duplicate link target.');
    }
    $sourceTargets[$target['url_hash']] = $target;
}

$destinationTargets = [];
$targetRows = $query(
    $pdo,
    'SELECT id, url_hash, normalized_url, kind FROM ' . $targetTable . " WHERE kind = 'external'",
);
while (($row = $targetRows->fetch(\PDO::FETCH_ASSOC)) !== false) {
    $destinationTargets[(string)$row['url_hash']] = $row;
}
if (count($destinationTargets) !== count($sourceTargets)) {
    throw new RuntimeException(sprintf(
        'Destination link inventory has %d external targets; the snapshot has %d.',
        count($destinationTargets),
        count($sourceTargets),
    ));
}

$targetIds = [];
foreach ($sourceTargets as $hash => $sourceTarget) {
    $destination = $destinationTargets[$hash] ?? null;
    if (!\is_array($destination)
        || (string)$destination['normalized_url'] !== $sourceTarget['normalized_url']
        || (string)$destination['kind'] !== 'external'
    ) {
        throw new RuntimeException('Destination link inventory differs at URL hash ' . $hash . '.');
    }
    $targetIds[$hash] = (int)$destination['id'];
}

$sourceChecks = [];
foreach ($snapshot['checks'] as $check) {
    if (!\is_array($check)
        || !\is_string($check['target_hash'] ?? null)
        || !isset($targetIds[$check['target_hash']])
        || !\is_string($check['probe_token'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/D', $check['probe_token']) !== 1
    ) {
        throw new UnexpectedValueException('The snapshot contains an invalid link check.');
    }
    $sourceChecks[] = $check;
}

$report = [
    'mode' => isset($options['apply']) ? 'apply' : 'dry-run',
    'matched_targets' => count($sourceTargets),
    'checks' => count($sourceChecks),
    'updated_targets' => 0,
    'replaced_checks' => 0,
    'removed_queue_jobs' => 0,
];
if (!isset($options['apply'])) {
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if ($pdo->inTransaction()) {
    throw new LogicException('Link-health import requires its own transaction.');
}
$pdo->beginTransaction();
try {
    $update = $pdo->prepare(
        'UPDATE ' . $targetTable . ' SET health_status = :health_status, http_status = :http_status, '
        . 'failure_count = :failure_count, effective_url = :effective_url, last_error = :last_error, '
        . 'last_checked_at = :last_checked_at, last_success_at = :last_success_at, next_check_at = :next_check_at, '
        . 'archive_status = :archive_status, archive_url = :archive_url, archive_timestamp = :archive_timestamp, '
        . 'archive_checked_at = :archive_checked_at, archive_lookup_token = :archive_lookup_token WHERE id = :id',
    );
    if (!$update instanceof \PDOStatement) {
        throw new RuntimeException('Unable to prepare the target update query.');
    }
    foreach ($sourceTargets as $hash => $target) {
        $update->execute([
            'health_status' => $target['health_status'],
            'http_status' => $target['http_status'],
            'failure_count' => $target['failure_count'],
            'effective_url' => $target['effective_url'],
            'last_error' => $target['last_error'],
            'last_checked_at' => $target['last_checked_at'],
            'last_success_at' => $target['last_success_at'],
            'next_check_at' => $target['next_check_at'],
            'archive_status' => $target['archive_status'],
            'archive_url' => $target['archive_url'],
            'archive_timestamp' => $target['archive_timestamp'],
            'archive_checked_at' => $target['archive_checked_at'],
            'archive_lookup_token' => $target['archive_lookup_token'],
            'id' => $targetIds[$hash],
        ]);
        ++$report['updated_targets'];
    }

    $report['replaced_checks'] = $deleteByTargetIds($pdo, $checkTable, array_values($targetIds));
    $insert = $pdo->prepare(
        'INSERT INTO ' . $checkTable . ' '
        . '(target_id, probe_token, checked_at, health_status, http_status, effective_url, error) '
        . 'VALUES (:target_id, :probe_token, :checked_at, :health_status, :http_status, :effective_url, :error)',
    );
    if (!$insert instanceof \PDOStatement) {
        throw new RuntimeException('Unable to prepare the link-check insert query.');
    }
    foreach ($sourceChecks as $check) {
        $insert->execute([
            'target_id' => $targetIds[$check['target_hash']],
            'probe_token' => $check['probe_token'],
            'checked_at' => $check['checked_at'],
            'health_status' => $check['health_status'],
            'http_status' => $check['http_status'],
            'effective_url' => $check['effective_url'],
            'error' => $check['error'],
        ]);
    }
    $report['replaced_checks'] = count($sourceChecks);

    $jobIds = array_map(static fn(int $id): string => 'target-' . $id, array_values($targetIds));
    foreach (array_chunk($jobIds, 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $params = ['register_link_check', ...$chunk];
        $statement = $query(
            $pdo,
            'DELETE FROM ' . $queueTable . ' WHERE code = ? AND id IN (' . $placeholders . ')',
            $params,
        );
        $report['removed_queue_jobs'] += $statement->rowCount();
    }

    $pdo->commit();
} catch (Throwable $throwable) {
    try {
        $pdo->rollBack();
    } catch (Throwable) {
        // Preserve the operation failure if commit already closed the transaction.
    }
    throw $throwable;
}

echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
