<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Symfony\Component\Cache\Adapter\ApcuAdapter;

/**
 * Bounded volatile presence storage for shared hosting.
 *
 * APCu is preferred when it is shared by the web workers. A fixed set of small,
 * locked JSON shards is the portable fallback; process memory is the last resort.
 */
final class AnalyticsPresenceStore
{
    private const int SHARDS = 16;

    private const int ENTRY_TTL = 90;

    private const int MAX_ENTRIES_PER_SHARD = 192;

    private const int MAX_FILE_BYTES = 262144;

    /** @var array<string, array{visitor_key: string, path: string, title: string, seen_at: int}> */
    private array $localEntries = [];

    private readonly string $fallbackDirectory;

    public function __construct(
        string                $fallbackDirectory,
        private readonly string $namespace,
        private readonly bool $useApcu = true,
    ) {
        if (preg_match('/^[a-f0-9]{8,64}$/D', $namespace) !== 1) {
            throw new \InvalidArgumentException('Invalid analytics presence namespace.');
        }

        $this->fallbackDirectory = rtrim($fallbackDirectory, '/\\') . DIRECTORY_SEPARATOR;
    }

    public function touch(
        string $pageViewKey,
        string $visitorKey,
        string $path,
        string $title,
        int $now,
    ): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $pageViewKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $visitorKey) !== 1
            || $path === ''
            || \strlen($path) > 1024
            || \strlen($title) > 255
            || $now <= 0
        ) {
            throw new \InvalidArgumentException('Invalid analytics presence entry.');
        }

        $entry = [
            'visitor_key' => $visitorKey,
            'path'        => $path,
            'title'       => $title,
            'seen_at'     => $now,
        ];
        $shard = $this->shard($pageViewKey);

        if ($this->apcuAvailable()) {
            $this->touchApcu($shard, $pageViewKey, $entry, $now);
            return;
        }

        try {
            if ($this->touchFilesystem($shard, $pageViewKey, $entry, $now)) {
                return;
            }
        } catch (\Throwable) {
            // Realtime presence must never make the existing live-update request fail.
        }

        $this->touchLocal($pageViewKey, $entry, $now);
    }

    /** @return list<array{visitor_key: string, path: string, title: string, seen_at: int}> */
    public function snapshot(int $now, int $maximumAge = 45): array
    {
        if ($now <= 0 || $maximumAge < 15 || $maximumAge > self::ENTRY_TTL) {
            throw new \InvalidArgumentException('Invalid analytics presence snapshot window.');
        }

        $minimumSeenAt = $now - $maximumAge;
        $entries = [];
        if ($this->apcuAvailable()) {
            for ($shard = 0; $shard < self::SHARDS; $shard++) {
                $stored = apcu_fetch($this->apcuKey($shard));
                if (\is_array($stored)) {
                    $entries += $this->validEntries($stored, $minimumSeenAt);
                }
            }
        } else {
            try {
                for ($shard = 0; $shard < self::SHARDS; $shard++) {
                    $entries += $this->readFilesystem($shard, $minimumSeenAt);
                }
            } catch (\Throwable) {
                $entries = $this->validEntries($this->localEntries, $minimumSeenAt);
            }
        }

        uasort(
            $entries,
            static fn(array $left, array $right): int => $right['seen_at'] <=> $left['seen_at'],
        );
        return array_values($entries);
    }

    private function apcuAvailable(): bool
    {
        return $this->useApcu
            && ApcuAdapter::isSupported()
            && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL));
    }

    /**
     * @param array{visitor_key: string, path: string, title: string, seen_at: int} $entry
     */
    private function touchApcu(int $shard, string $pageViewKey, array $entry, int $now): void
    {
        $key     = $this->apcuKey($shard);
        $lockKey = $key . '_lock';
        if (!apcu_add($lockKey, 1, 2)) {
            return;
        }

        try {
            $stored  = apcu_fetch($key);
            $entries = \is_array($stored)
                ? $this->validEntries($stored, $now - self::ENTRY_TTL)
                : [];
            $entries[$pageViewKey] = $entry;
            $this->capEntries($entries);
            apcu_store($key, $entries, self::ENTRY_TTL + 30);
        } finally {
            apcu_delete($lockKey);
        }
    }

    /**
     * @param array{visitor_key: string, path: string, title: string, seen_at: int} $entry
     */
    private function touchFilesystem(int $shard, string $pageViewKey, array $entry, int $now): bool
    {
        $this->ensureFallbackDirectory();
        $path   = $this->filePath($shard);
        $handle = register_call_without_warnings(static fn() => fopen($path, 'c+b'));
        if ($handle === false) {
            throw new \RuntimeException('Unable to open an analytics presence shard.');
        }

        try {
            register_call_without_warnings(static fn(): bool => chmod($path, 0600));
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }

            rewind($handle);
            $contents = stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read an analytics presence shard.');
            }
            $entries = \strlen($contents) <= self::MAX_FILE_BYTES
                ? $this->decodeEntries($contents, $now - self::ENTRY_TTL)
                : [];
            $entries[$pageViewKey] = $entry;
            $this->capEntries($entries);
            $this->writeEntries($handle, $entries);
            return true;
        } finally {
            register_call_without_warnings(static fn(): bool => flock($handle, LOCK_UN));
            fclose($handle);
        }
    }

    /** @return array<string, array{visitor_key: string, path: string, title: string, seen_at: int}> */
    private function readFilesystem(int $shard, int $minimumSeenAt): array
    {
        $path = $this->filePath($shard);
        if (!is_file($path)) {
            return [];
        }

        $handle = register_call_without_warnings(static fn() => fopen($path, 'rb'));
        if ($handle === false) {
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH | LOCK_NB)) {
                return [];
            }
            $contents = stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
            if ($contents === false || \strlen($contents) > self::MAX_FILE_BYTES) {
                return [];
            }
            return $this->decodeEntries($contents, $minimumSeenAt);
        } finally {
            register_call_without_warnings(static fn(): bool => flock($handle, LOCK_UN));
            fclose($handle);
        }
    }

    /**
     * @param array{visitor_key: string, path: string, title: string, seen_at: int} $entry
     */
    private function touchLocal(string $pageViewKey, array $entry, int $now): void
    {
        $this->localEntries = $this->validEntries($this->localEntries, $now - self::ENTRY_TTL);
        $this->localEntries[$pageViewKey] = $entry;
        while (\count($this->localEntries) > self::MAX_ENTRIES_PER_SHARD) {
            unset($this->localEntries[array_key_first($this->localEntries)]);
        }
    }

    /**
     * @param array<string, array{visitor_key: string, path: string, title: string, seen_at: int}> $entries
     */
    private function capEntries(array &$entries): void
    {
        uasort(
            $entries,
            static fn(array $left, array $right): int => $left['seen_at'] <=> $right['seen_at'],
        );
        while (\count($entries) > self::MAX_ENTRIES_PER_SHARD) {
            unset($entries[array_key_first($entries)]);
        }
    }

    /**
     * @param resource $handle
     * @param array<string, array{visitor_key: string, path: string, title: string, seen_at: int}> $entries
     */
    private function writeEntries($handle, array $entries): void
    {
        do {
            $contents = json_encode(
                $entries,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            if (\strlen($contents) <= self::MAX_FILE_BYTES) {
                break;
            }
            unset($entries[array_key_first($entries)]);
        } while ($entries !== []);

        if (!ftruncate($handle, 0) || !rewind($handle)) {
            throw new \RuntimeException('Unable to prepare an analytics presence shard.');
        }
        while ($contents !== '') {
            $written = fwrite($handle, $contents);
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write an analytics presence shard.');
            }
            $contents = substr($contents, $written);
        }
        if (!fflush($handle)) {
            throw new \RuntimeException('Unable to flush an analytics presence shard.');
        }
    }

    /** @return array<string, array{visitor_key: string, path: string, title: string, seen_at: int}> */
    private function decodeEntries(string $contents, int $minimumSeenAt): array
    {
        if ($contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return \is_array($decoded) ? $this->validEntries($decoded, $minimumSeenAt) : [];
    }

    /**
     * @param array<mixed> $entries
     * @return array<string, array{visitor_key: string, path: string, title: string, seen_at: int}>
     */
    private function validEntries(array $entries, int $minimumSeenAt): array
    {
        $valid = [];
        foreach ($entries as $pageViewKey => $entry) {
            if (!\is_string($pageViewKey)
                || preg_match('/^[a-f0-9]{64}$/D', $pageViewKey) !== 1
                || !\is_array($entry)
                || !isset($entry['visitor_key'], $entry['path'], $entry['title'], $entry['seen_at'])
                || !\is_string($entry['visitor_key'])
                || preg_match('/^[a-f0-9]{64}$/D', $entry['visitor_key']) !== 1
                || !\is_string($entry['path'])
                || $entry['path'] === ''
                || \strlen($entry['path']) > 1024
                || !\is_string($entry['title'])
                || \strlen($entry['title']) > 255
                || !\is_int($entry['seen_at'])
                || $entry['seen_at'] < $minimumSeenAt
            ) {
                continue;
            }
            $valid[$pageViewKey] = [
                'visitor_key' => $entry['visitor_key'],
                'path'        => $entry['path'],
                'title'       => $entry['title'],
                'seen_at'     => $entry['seen_at'],
            ];
        }
        return $valid;
    }

    private function ensureFallbackDirectory(): void
    {
        if (!is_dir($this->fallbackDirectory)
            && !mkdir($this->fallbackDirectory, 0700, true)
            && !is_dir($this->fallbackDirectory)
        ) {
            throw new \RuntimeException('Unable to create the analytics presence directory.');
        }
        register_call_without_warnings(fn(): bool => chmod($this->fallbackDirectory, 0700));
    }

    private function shard(string $pageViewKey): int
    {
        return (int)hexdec(substr($pageViewKey, 0, 2)) % self::SHARDS;
    }

    private function apcuKey(int $shard): string
    {
        return 'register_analytics_presence_' . $this->namespace . '_' . sprintf('%02d', $shard);
    }

    private function filePath(int $shard): string
    {
        return $this->fallbackDirectory . 'presence-' . sprintf('%02d', $shard) . '.json';
    }
}
