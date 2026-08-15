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
use Register\Backup\BackupRecoveryKeyLoader;
use Symfony\Component\Filesystem\Filesystem;

final class BackupRecoveryKeyLoaderTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_backup_recovery_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        if ($this->temporaryDirectory !== '') {
            (new Filesystem())->remove($this->temporaryDirectory);
        }
    }

    public function testLoadsDedicatedKeyFromModernConfig(): void
    {
        $secret = str_repeat('backup-recovery-secret-', 2);
        $config = $this->writeConfig([
            'backups' => ['encryption_key' => $secret],
        ]);

        $loaded = (new BackupRecoveryKeyLoader())->fromConfigFile($config);

        self::assertSame((new BackupEncryptionKeyProvider($secret))->key(), $loaded->key());
    }

    public function testFallsBackToMigratedDynamicSecretFile(): void
    {
        $secretFilename = $this->temporaryDirectory . '/private-secrets.php';
        $secret         = str_repeat('dynamic-antispam-secret-', 2);
        file_put_contents(
            $secretFilename,
            '<?php return ' . var_export(['S2_ANTISPAM_SECRET' => $secret], true) . ';',
        );
        $config = $this->writeConfig([
            'security' => [
                'antispam_secret' => null,
                'secret_file'     => basename($secretFilename),
            ],
            'backups' => ['encryption_key' => null],
        ]);

        $loaded = (new BackupRecoveryKeyLoader())->fromConfigFile($config);

        self::assertSame((new BackupEncryptionKeyProvider($secret))->key(), $loaded->key());
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): string
    {
        $filename = $this->temporaryDirectory . '/config.php';
        file_put_contents($filename, '<?php return ' . var_export($config, true) . ';');

        return $filename;
    }
}
