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

final class BackupEncryptorTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_backup_crypto_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        if ($this->temporaryDirectory !== '') {
            (new Filesystem())->remove($this->temporaryDirectory);
        }
    }

    public function testRoundTripUsesAuthenticatedStreamingEnvelope(): void
    {
        $plaintext = random_bytes(1024 * 1024 + 37);
        $source    = $this->temporaryDirectory . '/backup.zip';
        $encrypted = $source . '.enc';
        $decrypted = $this->temporaryDirectory . '/restored.zip';
        file_put_contents($source, $plaintext);

        $encryptor = $this->encryptor('first-encryption-secret');
        $encryptor->encryptFile($source, $encrypted);

        $envelope = file_get_contents($encrypted);
        self::assertIsString($envelope);
        self::assertStringStartsWith("REGISTER-BACKUP\0\x01", $envelope);
        self::assertStringNotContainsString(substr($plaintext, 100, 64), $envelope);
        if (DIRECTORY_SEPARATOR !== '\\') {
            $permissions = fileperms($encrypted);
            self::assertIsInt($permissions);
            self::assertSame(0600, $permissions & 0777);
        }

        $encryptor->decryptFile($encrypted, $decrypted);
        self::assertSame(hash('sha256', $plaintext), hash_file('sha256', $decrypted));
    }

    public function testTamperingFailsClosedAndRemovesPartialPlaintext(): void
    {
        $source      = $this->temporaryDirectory . '/backup.zip';
        $encrypted   = $source . '.enc';
        $destination = $this->temporaryDirectory . '/should-not-exist.zip';
        file_put_contents($source, 'sensitive backup data');
        $encryptor = $this->encryptor('first-encryption-secret');
        $encryptor->encryptFile($source, $encrypted);

        $contents = file_get_contents($encrypted);
        self::assertIsString($contents);
        $contents[\strlen($contents) - 1] = chr(ord($contents[\strlen($contents) - 1]) ^ 1);
        file_put_contents($encrypted, $contents);

        try {
            $encryptor->decryptFile($encrypted, $destination);
            self::fail('A modified backup envelope must not decrypt.');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('authentication', $runtimeException->getMessage());
        }

        self::assertFileDoesNotExist($destination);
    }

    public function testWrongInstallationSecretCannotDecryptBackup(): void
    {
        $source      = $this->temporaryDirectory . '/backup.zip';
        $encrypted   = $source . '.enc';
        $destination = $this->temporaryDirectory . '/wrong-key.zip';
        file_put_contents($source, 'sensitive backup data');
        $this->encryptor('first-encryption-secret')->encryptFile($source, $encrypted);

        try {
            $this->encryptor('second-encryption-secret')->decryptFile($encrypted, $destination);
            self::fail('A backup encrypted by another installation must not decrypt.');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('authentication', $runtimeException->getMessage());
        }

        self::assertFileDoesNotExist($destination);
    }

    public function testRecipientEncryptionCanOnlyBeOpenedWithOfflinePrivateKey(): void
    {
        $source      = $this->temporaryDirectory . '/recipient-backup.zip';
        $encrypted   = $source . '.enc';
        $destination = $this->temporaryDirectory . '/recipient-restored.zip';
        $plaintext   = random_bytes(1024 * 1024 + 19);
        file_put_contents($source, $plaintext);

        [$publicKey, $privateKey] = $this->recipientKeyPair();
        $publicEncryptor = new BackupEncryptor(new BackupEncryptionKeyProvider('', $publicKey));
        $publicEncryptor->encryptFile($source, $encrypted);

        $envelope = file_get_contents($encrypted);
        self::assertIsString($envelope);
        self::assertStringStartsWith("REGISTER-BACKUP\0\x02", $envelope);
        self::assertStringNotContainsString(substr($plaintext, 100, 64), $envelope);

        try {
            $publicEncryptor->decryptFile($encrypted, $destination);
            self::fail('A live-host public key must not decrypt a recipient-encrypted backup.');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('offline', $runtimeException->getMessage());
        }

        self::assertFileDoesNotExist($destination);

        $offlineEncryptor = new BackupEncryptor(
            new BackupEncryptionKeyProvider('', $publicKey, $privateKey),
        );
        $offlineEncryptor->decryptFile($encrypted, $destination);
        self::assertSame(hash('sha256', $plaintext), hash_file('sha256', $destination));
    }

    public function testWrongRecipientKeyFailsClosedWithoutPartialPlaintext(): void
    {
        $source      = $this->temporaryDirectory . '/recipient-backup.zip';
        $encrypted   = $source . '.enc';
        $destination = $this->temporaryDirectory . '/wrong-recipient.zip';
        file_put_contents($source, 'recipient encrypted backup data');
        [$publicKey] = $this->recipientKeyPair();
        (new BackupEncryptor(new BackupEncryptionKeyProvider('', $publicKey)))
            ->encryptFile($source, $encrypted);

        [$wrongPublicKey, $wrongPrivateKey] = $this->recipientKeyPair();
        $wrongEncryptor = new BackupEncryptor(
            new BackupEncryptionKeyProvider('', $wrongPublicKey, $wrongPrivateKey),
        );
        try {
            $wrongEncryptor->decryptFile($encrypted, $destination);
            self::fail('A backup encrypted for another recipient must not decrypt.');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('authentication', $runtimeException->getMessage());
        }

        self::assertFileDoesNotExist($destination);
    }

    public function testRecipientKeyEnvelopeTamperingFailsAuthentication(): void
    {
        $source      = $this->temporaryDirectory . '/recipient-backup.zip';
        $encrypted   = $source . '.enc';
        $destination = $this->temporaryDirectory . '/tampered-recipient.zip';
        file_put_contents($source, 'recipient encrypted backup data');
        [$publicKey, $privateKey] = $this->recipientKeyPair();
        (new BackupEncryptor(new BackupEncryptionKeyProvider('', $publicKey)))
            ->encryptFile($source, $encrypted);

        $envelope = file_get_contents($encrypted);
        self::assertIsString($envelope);
        $wrappedKeyOffset = \strlen("REGISTER-BACKUP\0\x02");
        $envelope[$wrappedKeyOffset] = chr(ord($envelope[$wrappedKeyOffset]) ^ 1);
        file_put_contents($encrypted, $envelope);

        try {
            (new BackupEncryptor(new BackupEncryptionKeyProvider('', $publicKey, $privateKey)))
                ->decryptFile($encrypted, $destination);
            self::fail('A modified recipient key envelope must not decrypt.');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('authentication', $runtimeException->getMessage());
        }

        self::assertFileDoesNotExist($destination);
    }

    public function testRejectsTruncatedAppendedAndReorderedFrames(): void
    {
        $source = $this->temporaryDirectory . '/multiframe.zip';
        file_put_contents($source, random_bytes(2 * 1024 * 1024 + 37));
        $encrypted = $source . '.enc';
        $encryptor = $this->encryptor('first-encryption-secret');
        $encryptor->encryptFile($source, $encrypted);

        $envelope = file_get_contents($encrypted);
        self::assertIsString($envelope);
        $headerBytes = \strlen("REGISTER-BACKUP\0\x01")
            + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $frameBytes = 1024 * 1024 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $firstFrame = substr($envelope, $headerBytes, $frameBytes);
        $secondFrame = substr($envelope, $headerBytes + $frameBytes, $frameBytes);
        self::assertSame($frameBytes, \strlen($firstFrame));
        self::assertSame($frameBytes, \strlen($secondFrame));

        $variants = [
            'truncated' => substr($envelope, 0, -1),
            'appended'  => $envelope . 'unexpected trailing data',
            'reordered' => substr($envelope, 0, $headerBytes)
                . $secondFrame
                . $firstFrame
                . substr($envelope, $headerBytes + 2 * $frameBytes),
        ];
        foreach ($variants as $name => $contents) {
            $modified = $this->temporaryDirectory . '/' . $name . '.enc';
            $output   = $this->temporaryDirectory . '/' . $name . '.zip';
            file_put_contents($modified, $contents);

            $rejected = false;
            try {
                $encryptor->decryptFile($modified, $output);
            } catch (\RuntimeException) {
                $rejected = true;
            }

            self::assertTrue($rejected, 'A structurally modified backup envelope must not decrypt.');
            self::assertFileDoesNotExist($output);
        }
    }

    public function testRefusesToOverwriteOrDeleteAnExistingDestination(): void
    {
        $source      = $this->temporaryDirectory . '/backup.zip';
        $destination = $this->temporaryDirectory . '/existing.enc';
        file_put_contents($source, 'backup');
        file_put_contents($destination, 'keep-existing-file');

        try {
            $this->encryptor('first-encryption-secret')->encryptFile($source, $destination);
            self::fail('An existing destination must not be overwritten.');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('overwrite', $runtimeException->getMessage());
        }

        self::assertSame('keep-existing-file', file_get_contents($destination));
    }

    private function encryptor(string $prefix): BackupEncryptor
    {
        return new BackupEncryptor(
            new BackupEncryptionKeyProvider(str_pad($prefix, 40, '-')),
        );
    }

    /** @return array{0: string, 1: string} */
    private function recipientKeyPair(): array
    {
        $keyPair = sodium_crypto_box_keypair();

        return [
            sodium_bin2base64(
                sodium_crypto_box_publickey($keyPair),
                SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
            ),
            sodium_bin2base64(
                sodium_crypto_box_secretkey($keyPair),
                SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
            ),
        ];
    }
}
