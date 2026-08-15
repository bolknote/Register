#!/usr/bin/env php
<?php
/**
 * Creates an offline recovery keypair for recipient-encrypted Register backups.
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Register backup keys can only be generated from the command line.');
}

$arguments = $_SERVER['argv'] ?? [];
if (\count($arguments) !== 2) {
    fwrite(STDERR, "Usage: php tools/generate-backup-keypair.php <recovery-config.php>\n");
    exit(64);
}

try {
    $destination = backupRecoveryAbsolutePath($arguments[1]);
    writeBackupRecoveryConfig($destination);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Backup key generation failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

function writeBackupRecoveryConfig(string $destination): void
{
    if (file_exists($destination) || is_link($destination)) {
        throw new RuntimeException('Refusing to overwrite an existing recovery configuration.');
    }

    if (!is_dir(\dirname($destination))) {
        throw new RuntimeException('The recovery configuration directory does not exist.');
    }

    $keyPair   = sodium_crypto_box_keypair();
    $publicKey = sodium_bin2base64(
        sodium_crypto_box_publickey($keyPair),
        SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
    );
    $privateKey = sodium_bin2base64(
        sodium_crypto_box_secretkey($keyPair),
        SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
    );
    $config = [
        'backups' => [
            'recipient_public_key'  => $publicKey,
            'recipient_private_key' => $privateKey,
        ],
    ];
    $contents = "<?php\n\ndeclare(strict_types = 1);\n\nreturn " . var_export($config, true) . ";\n";

    $stream  = null;
    $created = false;
    try {
        $stream = fopen($destination, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Unable to create the recovery configuration.');
        }
        $created = true;

        if (DIRECTORY_SEPARATOR !== '\\' && !chmod($destination, 0600)) {
            throw new RuntimeException('Unable to secure the recovery configuration.');
        }

        writeBackupRecoveryData($stream, $contents);
        if (!fflush($stream) || (\function_exists('fsync') && !fsync($stream))) {
            throw new RuntimeException('Unable to synchronize the recovery configuration.');
        }
    } catch (Throwable $throwable) {
        if (\is_resource($stream)) {
            fclose($stream);
            $stream = null;
        }
        if ($created && is_file($destination)) {
            unlink($destination);
        }

        throw $throwable;
    } finally {
        unset($config);
        sodium_memzero($contents);
        sodium_memzero($privateKey);
        sodium_memzero($keyPair);
        if (\is_resource($stream)) {
            fclose($stream);
        }
    }

    fwrite(STDOUT, 'Recovery configuration written: ' . $destination . PHP_EOL);
    fwrite(STDOUT, "Add only this value to the live config.php backups section:\n");
    fwrite(STDOUT, "'recipient_public_key' => " . var_export($publicKey, true) . ",\n");
}

/** @param resource $stream */
function writeBackupRecoveryData($stream, string $data): void
{
    $offset = 0;
    while ($offset < \strlen($data)) {
        $written = fwrite($stream, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Unable to write the recovery configuration.');
        }

        $offset += $written;
    }
}

function backupRecoveryAbsolutePath(string $path): string
{
    if ($path === '') {
        throw new InvalidArgumentException('A recovery configuration path cannot be empty.');
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
