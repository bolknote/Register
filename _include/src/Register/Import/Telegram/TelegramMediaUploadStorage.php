<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Register\Import\ExternalImportMapRepository;

/** Private resumable uploads, without any outgoing connection from the blog. */
final readonly class TelegramMediaUploadStorage implements TelegramFileClientInterface
{
    public const int MAX_BYTES = 20_000_000;

    public const int CHUNK_BYTES = 1_048_576;

    private const int MAX_STAGED_BYTES = 200_000_000;

    public function __construct(private string $directory, private int $chatId, private ExternalImportMapRepository $maps, private TelegramManagedMediaStorage $managed)
    {
    }

    /** @return array{received: int, complete: bool, owned: bool} */
    public function status(int $messageId, string $uniqueId): array
    {
        if ($this->owned($messageId, $uniqueId)) {
            return ['received' => 0, 'complete' => true, 'owned' => true];
        }

        $prefix = $this->prefix($messageId, $uniqueId);
        $meta = $this->metadata($prefix);
        clearstatcache(true, $prefix . '.part');
        $size = is_file($prefix . '.part') ? filesize($prefix . '.part') : 0;
        $size = $size === false ? 0 : $size;

        $complete = $meta !== [] && $size === (int)$meta['size'];
        return ['received' => $complete ? $size : intdiv($size, self::CHUNK_BYTES) * self::CHUNK_BYTES, 'complete' => $complete, 'owned' => false];
    }

    /** Reserve an entire comment before accepting any of its bytes.
     * @param list<array{file_unique_id: string, file_size: int}> $files
     */
    public function reserveMessage(int $messageId, array $files): void
    {
        if ($files === [] || \count($files) > 10) {
            throw new \UnexpectedValueException('The media reservation is invalid.');
        }

        $entries = [];
        foreach ($files as $file) {
            $total = $file['file_size'];
            $prefix = $this->prefix($messageId, $file['file_unique_id']);
            if ($total <= 0 || $total > self::MAX_BYTES || (isset($entries[$prefix]) && $entries[$prefix] !== $total)) {
                throw new \UnexpectedValueException('The media reservation is invalid.');
            }

            $entries[$prefix] = $total;
        }

        foreach ($files as $file) {
            if ($this->owned($messageId, $file['file_unique_id'])) {
                unset($entries[$this->prefix($messageId, $file['file_unique_id'])]);
            }
        }

        $this->ensureDirectory();
        $this->reserveFiles($entries);
    }

    /** @return array{received: int, complete: bool, owned: bool} */
    public function append(int $messageId, string $uniqueId, int $total, int $offset, string $bytes): array
    {
        if ($bytes === '' || $total <= 0 || $total > self::MAX_BYTES || $offset < 0 || $offset >= $total
            || $offset % self::CHUNK_BYTES !== 0 || \strlen($bytes) !== min(self::CHUNK_BYTES, $total - $offset)) {
            throw new \UnexpectedValueException('The media chunk range is invalid.');
        }

        if ($this->owned($messageId, $uniqueId)) {
            return ['received' => $total, 'complete' => true, 'owned' => true];
        }

        $this->ensureDirectory();
        $prefix = $this->prefix($messageId, $uniqueId);
        $lock = fopen($prefix . '.lock', 'c');
        if (!\is_resource($lock)) {
            throw new \RuntimeException('The media upload lock is unavailable.');
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('The media upload is busy.');
            }

            $meta = $this->metadata($prefix);
            if ($meta !== [] && (int)$meta['size'] !== $total) {
                throw new \UnexpectedValueException('The media upload size changed.');
            }

            if ($meta === []) {
                if ($offset !== 0) {
                    throw new \UnexpectedValueException('The media upload has a missing chunk.');
                }

                $this->reserveFiles([$prefix => $total]);
            }

            $file = fopen($prefix . '.part', 'c+b');
            if (!\is_resource($file)) {
                throw new \RuntimeException('The media upload cannot be staged.');
            }

            try {
                $received = fstat($file)['size'] ?? 0;
                if ($received < $total && $received % self::CHUNK_BYTES !== 0 && $offset === intdiv($received, self::CHUNK_BYTES) * self::CHUNK_BYTES) {
                    // A killed PHP worker may leave only part of an unacknowledged chunk.
                    ftruncate($file, $offset);
                    $received = $offset;
                }

                if ($offset < $received) {
                    fseek($file, $offset);
                    if ($offset + \strlen($bytes) > $received || fread($file, \strlen($bytes)) !== $bytes) {
                        throw new \UnexpectedValueException('A repeated media chunk does not match.');
                    }
                } elseif ($offset === $received) {
                    fseek($file, $offset);
                    for ($written = 0; $written < \strlen($bytes);) {
                        $count = fwrite($file, substr($bytes, $written));
                        if ($count === false || $count === 0) {
                            ftruncate($file, $received);
                            throw new \RuntimeException('The media upload cannot be written.');
                        }

                        $written += $count;
                    }

                    fflush($file);
                    $received += \strlen($bytes);
                } else {
                    throw new \UnexpectedValueException('The media upload has a missing chunk.');
                }

                chmod($prefix . '.part', 0600);
                $json = json_encode(['size' => $total, 'updated_at' => time()], JSON_THROW_ON_ERROR);
                if (file_put_contents($prefix . '.json', $json, LOCK_EX) !== \strlen($json)) {
                    throw new \RuntimeException('The media upload metadata cannot be written.');
                }

                return ['received' => $received, 'complete' => $received === $total, 'owned' => false];
            } finally {
                fclose($file);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    #[\Override]
    public function download(array $media): ?string
    {
        if ((int)($media['file_size'] ?? 0) > self::MAX_BYTES) {
            return null;
        }

        if (preg_match('~^live/([1-9][0-9]*)/~D', (string)($media['path'] ?? ''), $matches) !== 1) {
            throw new TelegramMediaDownloadFailed();
        }

        $prefix = $this->prefix((int)$matches[1], (string)($media['file_unique_id'] ?? ''));
        $meta = $this->metadata($prefix);
        $content = is_file($prefix . '.part') ? file_get_contents($prefix . '.part') : false;
        if ($meta === [] || !\is_string($content) || \strlen($content) !== (int)$meta['size']) {
            throw new TelegramMediaDownloadFailed();
        }

        return $content;
    }

    /** @param array<mixed> $messages */
    public function discardImported(array $messages): void
    {
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }

            foreach ((array)($message['telegram_media'] ?? []) as $media) {
                // A successful import also acknowledges stale sources and preserved
                // local edits. Their staged bytes no longer need quota reservations.
                if (\is_array($media)) {
                    $this->discard($this->prefix((int)$message['id'], (string)$media['file_unique_id']));
                }
            }
        }
    }

    private function discard(string $prefix): void
    {
        if (!is_file($prefix . '.lock') || is_link($prefix . '.lock')) {
            return;
        }

        $lock = fopen($prefix . '.lock', 'c');
        if (!\is_resource($lock)) {
            return;
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }

            foreach (['.part', '.json'] as $extension) {
                if (is_file($prefix . $extension) && !is_link($prefix . $extension)) {
                    unlink($prefix . $extension);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function prefix(int $messageId, string $uniqueId): string
    {
        if ($this->chatId <= 0 || $messageId <= 0 || $messageId > 9_007_199_254_740_991 || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $uniqueId) !== 1) {
            throw new \UnexpectedValueException('The media identity is invalid.');
        }

        $prefix = rtrim($this->directory, '/') . '/' . hash('sha256', $this->chatId . ':' . $messageId . ':' . $uniqueId);
        foreach (['.part', '.json', '.lock'] as $extension) {
            if (is_link($prefix . $extension)) {
                throw new \RuntimeException('A media upload must not be a symbolic link.');
            }
        }

        return $prefix;
    }

    /** @return array<string, mixed> */
    private function metadata(string $prefix): array
    {
        if (!is_file($prefix . '.json')) {
            return [];
        }

        $json = file_get_contents($prefix . '.json');
        $data = \is_string($json) ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : null;
        if (!\is_array($data) || !\is_int($data['size'] ?? null) || $data['size'] <= 0 || $data['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('The staged media metadata is invalid.');
        }

        return $data;
    }

    private function ensureDirectory(): void
    {
        if (is_link($this->directory) || is_link($this->directory . '/quota.lock')) {
            throw new \RuntimeException('The media staging directory must not be a symbolic link.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The media staging directory is unavailable.');
        }
    }

    /** @param array<string, int> $entries */
    private function reserveFiles(array $entries): void
    {
        $lock = fopen($this->directory . '/quota.lock', 'c');
        if (!\is_resource($lock)) {
            throw new \RuntimeException('The media quota lock is unavailable.');
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('The media quota is busy.');
            }

            $reserved = 0;
            $count = 0;
            $paths = glob(rtrim($this->directory, '/') . '/*.json');
            foreach ($paths === false ? [] : $paths as $path) {
                if (is_link($path)) {
                    continue;
                }

                $entryPrefix = substr($path, 0, -5);
                $meta = $this->metadata($entryPrefix);
                if ($meta === []) {
                    continue;
                }

                if ((int)($meta['updated_at'] ?? 0) < time() - 86400) {
                    $this->discard($entryPrefix);
                    if (!is_file($path)) {
                        continue;
                    }
                }

                $reserved += (int)$meta['size'];
                ++$count;
            }

            foreach ($entries as $prefix => $total) {
                $meta = $this->metadata($prefix);
                if ($meta !== [] && (int)$meta['size'] !== $total) {
                    throw new \UnexpectedValueException('The media upload size changed.');
                }

                if ($meta === []) {
                    $reserved += $total;
                    ++$count;
                }
            }

            if ($reserved > self::MAX_STAGED_BYTES || $count > 64) {
                throw new \RuntimeException('The private media staging quota is full.');
            }

            foreach ($entries as $prefix => $total) {
                $fileLock = fopen($prefix . '.lock', 'c');
                if (!\is_resource($fileLock)) {
                    throw new \RuntimeException('The media upload lock is unavailable.');
                }

                fclose($fileLock);
                $json = json_encode(['size' => $total, 'updated_at' => time()], JSON_THROW_ON_ERROR);
                if (file_put_contents($prefix . '.json', $json, LOCK_EX) !== \strlen($json)) {
                    throw new \RuntimeException('The media upload metadata cannot be written.');
                }

                chmod($prefix . '.json', 0600);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function owned(int $messageId, string $uniqueId): bool
    {
        $this->prefix($messageId, $uniqueId);
        $map = $this->maps->find('telegram', (string)$this->chatId, 'comment', (string)$messageId);
        $source = $map['source_data'] ?? null;
        $data = \is_array($source) ? ($source['telegram'] ?? null) : null;
        $telegram = \is_array($data) ? $data : [];
        $media = (array)($telegram['comment']['media'] ?? []);
        $state = (array)($telegram['media_state'] ?? []);
        foreach ($media as $position => $item) {
            if (!\is_array($item) || !\is_string($item['path'] ?? null) || preg_match('~^live/' . $messageId . '/[1-9][0-9]*-' . preg_quote($uniqueId, '~') . '\\.[a-z0-9]+$~D', $item['path']) !== 1) {
                continue;
            }

            $sourceIdentity = (string)($state[$position]['source_sha256'] ?? '');
            $prefix = hash('sha256', $item['path']) . ':';
            $file = str_starts_with($sourceIdentity, $prefix)
                ? $this->managed->findForMessage($this->chatId, $messageId, substr($sourceIdentity, \strlen($prefix))) : null;
            if ($file !== null && ($state[$position]['source_sha256'] ?? '') === hash('sha256', $item['path']) . ':' . $file['storage_id']) {
                return true;
            }
        }

        return false;
    }
}
