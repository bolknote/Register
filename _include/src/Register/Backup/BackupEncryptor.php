<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

/**
 * Streaming authenticated-encryption envelope for a portable backup ZIP.
 *
 * The fixed-size secretstream frames avoid loading a potentially large media archive into memory.
 */
final readonly class BackupEncryptor
{
    public const string FILE_SUFFIX = '.enc';

    private const string MAGIC = "REGISTER-BACKUP\0";

    private const string VERSION = "\x01";

    private const int CHUNK_BYTES = 1024 * 1024;

    public function __construct(private BackupEncryptionKeyProvider $keyProvider)
    {
        if (!\function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new \RuntimeException('The Sodium PHP extension is required to encrypt backups.');
        }
    }

    public function encryptFile(string $sourcePath, string $destinationPath): void
    {
        $sourceSize = $this->regularFileSize($sourcePath);
        $source     = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new \RuntimeException('Unable to open the backup ZIP for encryption.');
        }

        $destination        = null;
        $destinationCreated = false;
        $key                = null;
        try {
            $key = $this->keyProvider->key();
            $destination = $this->openPrivateOutput($destinationPath);
            $destinationCreated = true;
            [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->writeAll($destination, self::MAGIC . self::VERSION . $streamHeader);

            $remaining = $sourceSize;
            if ($remaining === 0) {
                $this->writeAll(
                    $destination,
                    sodium_crypto_secretstream_xchacha20poly1305_push(
                        $state,
                        '',
                        self::MAGIC . self::VERSION,
                        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
                    ),
                );
            }

            while ($remaining > 0) {
                $chunk = $this->readExactly($source, min(self::CHUNK_BYTES, $remaining));
                $remaining -= \strlen($chunk);
                $tag = $remaining === 0
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $this->writeAll(
                    $destination,
                    sodium_crypto_secretstream_xchacha20poly1305_push(
                        $state,
                        $chunk,
                        self::MAGIC . self::VERSION,
                        $tag,
                    ),
                );
            }

            $this->flush($destination);
        } catch (\Throwable $throwable) {
            if (\is_resource($destination)) {
                fclose($destination);
                $destination = null;
            }

            if ($destinationCreated && is_file($destinationPath)) {
                s2_call_without_warnings(static fn(): bool => unlink($destinationPath));
            }

            throw $throwable;
        } finally {
            if (\is_string($key)) {
                sodium_memzero($key);
            }

            fclose($source);
            if (\is_resource($destination)) {
                fclose($destination);
            }
        }
    }

    public function decryptFile(string $sourcePath, string $destinationPath): void
    {
        $this->regularFileSize($sourcePath);
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new \RuntimeException('Unable to open the encrypted backup.');
        }

        $destination        = null;
        $destinationCreated = false;
        $key                = null;
        try {
            $key = $this->keyProvider->key();
            $preamble = $this->readExactly($source, \strlen(self::MAGIC . self::VERSION));
            if (!hash_equals(self::MAGIC . self::VERSION, $preamble)) {
                throw new \RuntimeException('The file is not a supported encrypted Register backup.');
            }

            $streamHeader = $this->readExactly(
                $source,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES,
            );
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);
            $destination = $this->openPrivateOutput($destinationPath);
            $destinationCreated = true;
            $this->decryptFrames($source, $destination, $state);
            $this->flush($destination);
        } catch (\Throwable $throwable) {
            if (\is_resource($destination)) {
                fclose($destination);
                $destination = null;
            }

            if ($destinationCreated && is_file($destinationPath)) {
                s2_call_without_warnings(static fn(): bool => unlink($destinationPath));
            }

            throw $throwable;
        } finally {
            if (\is_string($key)) {
                sodium_memzero($key);
            }

            fclose($source);
            if (\is_resource($destination)) {
                fclose($destination);
            }
        }
    }

    /**
     * @param resource $source
     * @param resource $destination
     */
    private function decryptFrames($source, $destination, string $state): void
    {
        $ciphertextBytes = self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        while (true) {
            $ciphertext = $this->readUpTo($source, $ciphertextBytes);
            if ($ciphertext === '') {
                throw new \RuntimeException('The encrypted backup is truncated before its final frame.');
            }

            $result = $this->pullFrame($state, $ciphertext);
            if ($result === false) {
                throw new \RuntimeException('The encrypted backup failed authentication.');
            }

            [$plaintext, $tag] = $result;
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                if (fgetc($source) !== false) {
                    throw new \RuntimeException('The encrypted backup contains data after its final frame.');
                }

                $this->writeAll($destination, $plaintext);
                return;
            }

            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                || \strlen($ciphertext) !== $ciphertextBytes
            ) {
                throw new \RuntimeException('The encrypted backup contains an invalid frame sequence.');
            }

            $this->writeAll($destination, $plaintext);
        }
    }

    /** @return array{0: string, 1: int}|false */
    private function pullFrame(string &$state, string $ciphertext): array|false
    {
        return $this->normalizePullResult(sodium_crypto_secretstream_xchacha20poly1305_pull(
            $state,
            $ciphertext,
            self::MAGIC . self::VERSION,
        ));
    }

    /** @return array{0: string, 1: int}|false */
    private function normalizePullResult(mixed $result): array|false
    {
        if ($result === false) {
            return false;
        }

        if (!\is_array($result)
            || !isset($result[0], $result[1])
            || !\is_string($result[0])
            || !\is_int($result[1])
        ) {
            throw new \RuntimeException('Sodium returned an invalid backup decryption result.');
        }

        return [$result[0], $result[1]];
    }

    private function regularFileSize(string $path): int
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('A backup encryption input must be a regular file.');
        }

        $size = filesize($path);
        if ($size === false) {
            throw new \RuntimeException('Unable to determine the backup encryption input size.');
        }

        return $size;
    }

    /** @return resource */
    private function openPrivateOutput(string $path)
    {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException('Refusing to overwrite an existing backup file.');
        }

        $directory = \dirname($path);
        if (!is_dir($directory)) {
            throw new \RuntimeException('The backup output directory is not available.');
        }

        $stream = s2_call_without_warnings(static fn() => fopen($path, 'xb'));
        if ($stream === false) {
            throw new \RuntimeException('Unable to create the backup encryption output.');
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !chmod($path, 0600)) {
            fclose($stream);
            s2_call_without_warnings(static fn(): bool => unlink($path));
            throw new \RuntimeException('Unable to secure the backup encryption output.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private function readExactly($stream, int $length): string
    {
        $data = $this->readUpTo($stream, $length);
        if (\strlen($data) !== $length) {
            throw new \RuntimeException('The backup encryption input is truncated.');
        }

        return $data;
    }

    /** @param resource $stream */
    private function readUpTo($stream, int $length): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('A backup encryption read length must be positive.');
        }

        $data = '';
        while (\strlen($data) < $length && !feof($stream)) {
            $remaining = $length - \strlen($data);
            if ($remaining < 1) {
                break;
            }

            $chunk = fread($stream, $remaining);
            if ($chunk === false) {
                throw new \RuntimeException('Unable to read the backup encryption input.');
            }

            if ($chunk === '') {
                if (feof($stream)) {
                    break;
                }

                throw new \RuntimeException('Unable to make progress while reading a backup file.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    /** @param resource $stream */
    private function writeAll($stream, string $data): void
    {
        $offset = 0;
        while ($offset < \strlen($data)) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write the backup encryption output.');
            }

            $offset += $written;
        }
    }

    /** @param resource $stream */
    private function flush($stream): void
    {
        if (!fflush($stream)) {
            throw new \RuntimeException('Unable to flush the backup encryption output.');
        }

        if (\function_exists('fsync') && !fsync($stream)) {
            throw new \RuntimeException('Unable to synchronize the backup encryption output.');
        }
    }
}
