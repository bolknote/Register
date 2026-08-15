<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Backup;

use Codeception\Test\Unit;
use Register\Backup\BackupEncryptionKeyProvider;
use Register\Backup\BackupEncryptor;
use Symfony\Component\Filesystem\Filesystem;

final class BackupKeypairToolTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_backup_keys_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        if ($this->temporaryDirectory !== '') {
            (new Filesystem())->remove($this->temporaryDirectory);
        }
    }

    public function testCreatesPrivateRecoveryConfigWithoutPrintingPrivateKey(): void
    {
        $destination = $this->temporaryDirectory . '/backup-recovery.php';
        [$status, $output, $error] = $this->runTool($destination);

        self::assertSame(0, $status, $error);
        self::assertFileExists($destination);
        $config = include $destination;
        self::assertIsArray($config);
        $publicKey = $config['backups']['recipient_public_key'] ?? null;
        $privateKey = $config['backups']['recipient_private_key'] ?? null;
        self::assertIsString($publicKey);
        self::assertIsString($privateKey);
        self::assertStringContainsString($publicKey, $output);
        self::assertStringNotContainsString($privateKey, $output);
        self::assertSame(
            $publicKey,
            sodium_bin2base64(
                sodium_crypto_box_publickey_from_secretkey(sodium_base642bin(
                    $privateKey,
                    SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
                )),
                SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
            ),
        );
        if (DIRECTORY_SEPARATOR !== '\\') {
            $permissions = fileperms($destination);
            self::assertIsInt($permissions);
            self::assertSame(0600, $permissions & 0777);
        }

        $originalContents = file_get_contents($destination);
        [$secondStatus] = $this->runTool($destination);
        self::assertSame(1, $secondStatus);
        self::assertSame($originalContents, file_get_contents($destination));

        $source    = $this->temporaryDirectory . '/backup.zip';
        $encrypted = $source . '.enc';
        $restored  = $this->temporaryDirectory . '/restored.zip';
        file_put_contents($source, 'offline recipient recovery');
        (new BackupEncryptor(new BackupEncryptionKeyProvider('', $publicKey)))
            ->encryptFile($source, $encrypted);
        [$decryptStatus, $decryptOutput, $decryptError] = $this->runCommand([
            PHP_BINARY,
            \dirname(__DIR__, 4) . '/tools/decrypt-backup.php',
            $encrypted,
            $restored,
            $destination,
        ]);
        self::assertSame(0, $decryptStatus, $decryptError);
        self::assertStringContainsString($restored, $decryptOutput);
        self::assertSame('offline recipient recovery', file_get_contents($restored));
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runTool(string $destination): array
    {
        return $this->runCommand([
            PHP_BINARY,
            \dirname(__DIR__, 4) . '/tools/generate-backup-keypair.php',
            $destination,
        ]);
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCommand(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error  = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($output);
        self::assertIsString($error);

        return [proc_close($process), $output, $error];
    }
}
