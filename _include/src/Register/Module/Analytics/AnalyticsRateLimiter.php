<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Config\StringProxy;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\HttpFoundation\Request;

/** Volatile abuse control; durability is deliberately owned by AnalyticsSpool instead. */
final class AnalyticsRateLimiter
{
    private const int EVENTS_PER_MINUTE = 120;

    private const int FILE_SHARDS = 16;

    private const int MAX_IDENTITIES_PER_FILE = 2048;

    private const int MAX_FILE_BYTES = 262144;

    /** @var array<string, int> */
    private array $localCounters = [];

    private readonly ?string $fallbackDirectory;

    public function __construct(
        private readonly StringProxy $salt,
        ?string                      $fallbackDirectory = null,
        private readonly bool        $useApcu = true,
    ) {
        $this->fallbackDirectory = $fallbackDirectory === null
            ? null
            : rtrim($fallbackDirectory, '/\\') . DIRECTORY_SEPARATOR;
    }

    public function accepts(Request $request, string $visitorKey, int $events, int $now): bool
    {
        if ($events < 1 || $events > self::EVENTS_PER_MINUTE) {
            return false;
        }

        $identity = ($request->getClientIp() ?? 'unknown') . "\0" . $visitorKey;
        $digest   = hash_hmac('sha256', $identity, $this->salt->get());
        $key      = 'register_analytics_rate_' . intdiv($now, 60) . '_' . $digest;

        if ($this->apcuAvailable()) {
            return $this->acceptsInApcu($key, $events);
        }

        $fallbackDirectory = $this->fallbackDirectory;
        if ($fallbackDirectory !== null) {
            try {
                return $this->acceptsOnFilesystem($fallbackDirectory, $digest, $events, intdiv($now, 60));
            } catch (\Throwable) {
                // A best-effort process-local guard is safer than making collection unavailable.
            }
        }

        $count = ($this->localCounters[$key] ?? 0) + $events;
        $this->localCounters[$key] = $count;
        if (\count($this->localCounters) > 256) {
            array_shift($this->localCounters);
        }
        return $count <= self::EVENTS_PER_MINUTE;
    }

    private function apcuAvailable(): bool
    {
        return $this->useApcu
            && ApcuAdapter::isSupported()
            && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL));
    }

    private function acceptsInApcu(string $key, int $events): bool
    {
        if (apcu_add($key, $events, 120)) {
            return true;
        }

        $success = false;
        $count   = apcu_inc($key, $events, $success, 120);
        return $success && \is_int($count) && $count <= self::EVENTS_PER_MINUTE;
    }

    private function acceptsOnFilesystem(string $directory, string $digest, int $events, int $minute): bool
    {
        $this->ensureFallbackDirectory($directory);
        $shard = (int)hexdec(substr($digest, 0, 2)) % self::FILE_SHARDS;
        $path  = $directory
            . 'rate-' . sprintf('%010d', $minute) . '-' . sprintf('%02d', $shard) . '.json';
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the analytics rate-limit shard.');
        }

        try {
            if (!chmod($path, 0600)) {
                throw new \RuntimeException('Unable to protect the analytics rate-limit shard.');
            }
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the analytics rate-limit shard.');
            }

            rewind($handle);
            $contents = stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read the analytics rate-limit shard.');
            }
            if (\strlen($contents) > self::MAX_FILE_BYTES) {
                return false;
            }

            $counters = $this->decodeCounters($contents);
            $identity = substr($digest, 0, 32);
            if (!isset($counters[$identity]) && \count($counters) >= self::MAX_IDENTITIES_PER_FILE) {
                return false;
            }

            $count = min(self::EVENTS_PER_MINUTE + 1, ($counters[$identity] ?? 0) + $events);
            $counters[$identity] = $count;
            $this->writeCounters($handle, $counters);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->occasionallyRemoveExpiredFiles($directory, $digest, $minute);
        return $count <= self::EVENTS_PER_MINUTE;
    }

    /** @return array<string, int> */
    private function decodeCounters(string $contents): array
    {
        if ($contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 3, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $counters = [];
        foreach ($decoded as $identity => $count) {
            if (\is_string($identity)
                && preg_match('/^[a-f0-9]{32}$/D', $identity) === 1
                && \is_int($count)
                && $count >= 0
            ) {
                $counters[$identity] = min(self::EVENTS_PER_MINUTE + 1, $count);
            }
        }
        return $counters;
    }

    /**
     * @param resource $handle
     * @param array<string, int> $counters
     */
    private function writeCounters($handle, array $counters): void
    {
        $contents = json_encode($counters, JSON_THROW_ON_ERROR);
        if (\strlen($contents) > self::MAX_FILE_BYTES || !ftruncate($handle, 0) || !rewind($handle)) {
            throw new \RuntimeException('Unable to prepare the analytics rate-limit shard.');
        }

        while ($contents !== '') {
            $written = fwrite($handle, $contents);
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to update the analytics rate-limit shard.');
            }
            $contents = substr($contents, $written);
        }
        if (!fflush($handle)) {
            throw new \RuntimeException('Unable to flush the analytics rate-limit shard.');
        }
    }

    private function ensureFallbackDirectory(string $directory): void
    {
        if (!is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException('Unable to create the analytics rate-limit directory.');
        }
        if (!chmod($directory, 0700)) {
            throw new \RuntimeException('Unable to protect the analytics rate-limit directory.');
        }
    }

    private function occasionallyRemoveExpiredFiles(string $directory, string $digest, int $minute): void
    {
        if (($minute + (int)hexdec(substr($digest, -2))) % 128 !== 0) {
            return;
        }

        $files = glob($directory . 'rate-*.json', GLOB_NOSORT);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if (preg_match('/^rate-([0-9]{10})-[0-9]{2}\.json$/D', basename($file), $match) === 1
                && (int)$match[1] < $minute - 2
            ) {
                if (!unlink($file)) {
                    return;
                }
            }
        }
    }
}
