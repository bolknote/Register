#!/usr/bin/env php
<?php
/**
 * Decrypts a Register backup without booting the database-backed application.
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Backup\BackupEncryptor;
use Register\Backup\BackupRecoveryKeyLoader;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Register backups can only be decrypted from the command line.');
}

$projectRoot = \dirname(__DIR__);
require $projectRoot . '/_vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (\count($arguments) < 2 || \count($arguments) > 4) {
    fwrite(STDERR, "Usage: php tools/decrypt-backup.php <backup.zip.enc> [output.zip] [config.php]\n");
    exit(64);
}

$source = absolutePath($arguments[1]);
$output = isset($arguments[2]) ? absolutePath($arguments[2]) : preg_replace('/\.enc$/D', '', $source);
$config = isset($arguments[3]) ? absolutePath($arguments[3]) : $projectRoot . '/' . register_get_config_filename();
if (!\is_string($output) || $output === $source) {
    fwrite(STDERR, "The encrypted backup filename must end in .enc or an explicit output path is required.\n");
    exit(64);
}

try {
    $keyProvider = (new BackupRecoveryKeyLoader())->fromConfigFile($config);
    (new BackupEncryptor($keyProvider))->decryptFile($source, $output);
    fwrite(STDOUT, $output . PHP_EOL);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Backup decryption failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

function absolutePath(string $path): string
{
    if ($path === '') {
        throw new InvalidArgumentException('A backup path cannot be empty.');
    }
    if (str_starts_with($path, DIRECTORY_SEPARATOR)
        || str_starts_with($path, '\\\\')
        || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1
    ) {
        return $path;
    }

    $workingDirectory = getcwd();
    if ($workingDirectory === false) {
        throw new RuntimeException('Unable to determine the current working directory.');
    }

    return $workingDirectory . DIRECTORY_SEPARATOR . $path;
}
