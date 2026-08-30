<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

/** Copies media from a Telegram ZIP into the deliberately narrow comment-media directory. */
final readonly class TelegramManagedMediaStorage
{
    private const int MAX_MEDIA_BYTES = 200_000_000;

    private const string URL_ROOT = '/_pictures/bolknote/comments/telegram';

    public function __construct(private string $publicRootDir)
    {
        if ($publicRootDir === '') {
            throw new \InvalidArgumentException('The public root directory is not configured.');
        }
    }

    /**
     * @return array{
     *     url: string,
     *     kind: 'image'|'video'|'audio'|'file',
     *     mime_type: string,
     *     sha256: string,
     *     created_file: ?string
     * }|null
     */
    public function import(
        TelegramExportPackage $package,
        string                $relativePath,
        int                   $chatId,
        int                   $messageId,
        int                   $position,
        bool                  $dryRun,
    ): ?array {
        $expectedSize = $package->mediaSize($relativePath);
        $input = $package->openMediaStream($relativePath);
        if ($expectedSize === null || !\is_resource($input)) {
            return null;
        }

        $directory = rtrim($this->publicRootDir, '/') . self::URL_ROOT
            . '/' . $chatId . '/' . $messageId;
        $temporaryFile = null;
        $output = null;
        if (!$dryRun) {
            $this->ensureDirectory($directory);
            $temporaryFile = register_call_without_warnings(
                static fn(): string|false => tempnam($directory, '.telegram-'),
            );
            if (!\is_string($temporaryFile)) {
                fclose($input);
                throw new \RuntimeException('Unable to stage Telegram media.');
            }

            $output = register_call_without_warnings(static fn() => fopen($temporaryFile, 'wb'));
            if (!\is_resource($output)) {
                fclose($input);
                register_call_without_warnings(static fn(): bool => unlink($temporaryFile));
                throw new \RuntimeException('Unable to write staged Telegram media.');
            }
        }

        $hash = hash_init('sha256');
        $prefix = '';
        $bytes = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Unable to read Telegram media.');
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes += \strlen($chunk);
                if ($bytes > self::MAX_MEDIA_BYTES || $bytes > $expectedSize) {
                    throw new \UnexpectedValueException('Telegram media exceeds its declared size.');
                }

                if (\strlen($prefix) < 16_384) {
                    $prefix .= substr($chunk, 0, 16_384 - \strlen($prefix));
                }

                hash_update($hash, $chunk);

                if (\is_resource($output)) {
                    $this->writeChunk($output, $chunk);
                }
            }
        } catch (\Throwable $throwable) {
            if (\is_resource($output)) {
                fclose($output);
            }

            fclose($input);
            if ($temporaryFile !== null && is_file($temporaryFile)) {
                register_call_without_warnings(static fn(): bool => unlink($temporaryFile));
            }

            throw $throwable;
        }

        if (\is_resource($output)) {
            fclose($output);
        }

        fclose($input);

        if ($bytes !== $expectedSize) {
            if ($temporaryFile !== null && is_file($temporaryFile)) {
                register_call_without_warnings(static fn(): bool => unlink($temporaryFile));
            }

            throw new \UnexpectedValueException('Telegram media size does not match the ZIP directory.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($prefix);
        $mimeType = \is_string($mimeType) ? mb_strtolower($mimeType) : 'application/octet-stream';
        [$kind, $extension] = $this->mediaPresentation($mimeType);
        $digest = hash_final($hash);
        $filename = \sprintf('%02d-%s.%s', max(1, $position), substr($digest, 0, 20), $extension);
        $storagePath = $directory . '/' . $filename;
        $url = self::URL_ROOT . '/' . $chatId . '/' . $messageId . '/' . $filename;

        if ($dryRun) {
            return [
                'url'          => $url,
                'kind'         => $kind,
                'mime_type'    => $mimeType,
                'sha256'       => $digest,
                'created_file' => null,
            ];
        }

        $createdFile = $this->publishStagedMedia($temporaryFile, $storagePath);

        return [
            'url'          => $url,
            'kind'         => $kind,
            'mime_type'    => $mimeType,
            'sha256'       => $digest,
            'created_file' => $createdFile,
        ];
    }

    /** @param resource|null $output */
    private function writeChunk(mixed $output, string $chunk): void
    {
        if (!\is_resource($output)) {
            throw new \LogicException('Telegram media output is not an open stream.');
        }

        $offset = 0;
        $length = \strlen($chunk);
        while ($offset < $length) {
            $written = fwrite($output, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write Telegram media.');
            }

            $offset += $written;
        }
    }

    private function ensureDirectory(string $directory): void
    {
        $base = rtrim($this->publicRootDir, '/') . '/_pictures/bolknote/comments';
        if (!is_dir($base) || is_link($base)) {
            throw new \RuntimeException('The managed comment-media directory is unavailable.');
        }

        $current = $base;
        foreach (array_slice(explode('/', substr($directory, \strlen($base))), 1) as $segment) {
            if ($segment === '' || preg_match('/^[1-9][0-9]*$|^telegram$/D', $segment) !== 1) {
                throw new \LogicException('The Telegram media directory is invalid.');
            }

            $current .= '/' . $segment;
            if (!is_dir($current) && !mkdir($current, 0755) && !is_dir($current)) {
                throw new \RuntimeException('Unable to create the Telegram media directory.');
            }

            if (is_link($current)) {
                throw new \RuntimeException('The Telegram media directory must not be a symbolic link.');
            }
        }
    }

    private function publishStagedMedia(?string $temporaryFile, string $storagePath): ?string
    {
        if (!\is_string($temporaryFile)) {
            throw new \LogicException('The staged Telegram media path is unavailable.');
        }

        if (is_file($storagePath)) {
            register_call_without_warnings(static fn(): bool => unlink($temporaryFile));

            return null;
        }

        if (!register_call_without_warnings(static fn(): bool => rename($temporaryFile, $storagePath))) {
            register_call_without_warnings(static fn(): bool => unlink($temporaryFile));
            throw new \RuntimeException('Unable to publish Telegram media.');
        }

        if (!register_call_without_warnings(static fn(): bool => chmod($storagePath, 0644))) {
            register_call_without_warnings(static fn(): bool => unlink($storagePath));
            throw new \RuntimeException('Unable to protect Telegram media.');
        }

        return $storagePath;
    }

    /** @return array{0: 'image'|'video'|'audio'|'file', 1: string} */
    private function mediaPresentation(string $mimeType): array
    {
        return match ($mimeType) {
            'image/jpeg'      => ['image', 'jpg'],
            'image/png'       => ['image', 'png'],
            'image/gif'       => ['image', 'gif'],
            'image/webp'      => ['image', 'webp'],
            'image/avif'      => ['image', 'avif'],
            'video/mp4'       => ['video', 'mp4'],
            'video/webm'      => ['video', 'webm'],
            'video/quicktime' => ['video', 'mov'],
            'audio/mpeg'      => ['audio', 'mp3'],
            'audio/ogg'       => ['audio', 'ogg'],
            'audio/wav',
            'audio/x-wav'     => ['audio', 'wav'],
            'audio/mp4'       => ['audio', 'm4a'],
            'audio/webm'      => ['audio', 'webm'],
            default           => ['file', 'bin'],
        };
    }
}
