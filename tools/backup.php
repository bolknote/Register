<?php
/**
 * Creates a complete Register database-and-media archive on demand.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Backup\BackupManager;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Register backups can only be created from the command line.');
}

try {
    $app    = require dirname(__DIR__) . '/_include/common.php';
    $backup = $app->container->get(BackupManager::class)->createNow();
    fwrite(STDOUT, $backup->path . PHP_EOL);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Backup failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
