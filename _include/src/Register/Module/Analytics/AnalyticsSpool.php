<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

/** A bounded append-only disk queue that remains available without APCu, Redis, or a daemon. */
final readonly class AnalyticsSpool
{
    private const int DEFAULT_SHARDS = 4;

    private const int DEFAULT_SEGMENT_BYTES = 262144;

    private const int DEFAULT_MAX_SEALED_SEGMENTS = 128;

    private string $directory;

    public function __construct(
        string      $directory,
        private int $minimumSegmentAge = 10,
        private int $shards = self::DEFAULT_SHARDS,
        private int $segmentBytes = self::DEFAULT_SEGMENT_BYTES,
        private int $maxSealedSegments = self::DEFAULT_MAX_SEALED_SEGMENTS,
    ) {
        if ($minimumSegmentAge < 0 || $shards < 1 || $shards > 16 || $segmentBytes < 4096 || $maxSealedSegments < 1) {
            throw new \InvalidArgumentException('Invalid analytics spool limits.');
        }

        $this->directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
    }

    /** @param list<AnalyticsEvent> $events */
    public function append(array $events, ?int $now = null): void
    {
        if ($events === []) {
            return;
        }

        $now ??= time();
        $this->ensureDirectory();
        $byShard = [];
        foreach ($events as $event) {
            $line = json_encode(
                $event->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";
            // Preserve per-session ordering across independently drained shards.
            $byShard[$this->shard($event->sessionKey)][] = $line;
        }

        foreach ($byShard as $shard => $lines) {
            $this->appendToShard($shard, $lines, $now);
        }
    }

    public function hasDueWork(int $now): bool
    {
        if (!is_dir($this->directory)) {
            return false;
        }

        if ($this->sealedSegments(1) !== []) {
            return true;
        }

        foreach (range(0, $this->shards - 1) as $shard) {
            if ($this->activeIsDue($shard, $now)) {
                return true;
            }
        }

        return false;
    }

    /** Seals every old or full active shard and returns the number of rotated segments. */
    public function sealDue(int $now): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $sealed = 0;
        foreach (range(0, $this->shards - 1) as $shard) {
            if (!$this->activeIsDue($shard, $now)) {
                continue;
            }

            $lock = $this->openLock($shard);
            try {
                if (!flock($lock, LOCK_EX)) {
                    throw new AnalyticsSpoolException('Unable to lock an analytics spool shard.');
                }

                if ($this->activeIsDue($shard, $now) && $this->rotateUnderLock($shard, $now)) {
                    ++$sealed;
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $sealed;
    }

    /** @return list<string> */
    public function sealedSegments(int $limit = 4): array
    {
        if ($limit < 1 || !is_dir($this->directory)) {
            return [];
        }

        $segments = glob($this->directory . 'sealed-*.ndjson', GLOB_NOSORT);
        if ($segments === false || $segments === []) {
            return [];
        }

        sort($segments, SORT_STRING);
        return array_slice($segments, 0, $limit);
    }

    /** @return array{events: list<AnalyticsEvent>, invalid: int} */
    public function readSegment(string $path): array
    {
        $this->assertSegmentPath($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new AnalyticsSpoolException('Unable to open an analytics spool segment.');
        }

        $events  = [];
        $invalid = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                try {
                    $data = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                    if (!\is_array($data)) {
                        throw new \UnexpectedValueException('Analytics spool line must contain an object.');
                    }

                    $events[] = AnalyticsEvent::fromArray($data);
                } catch (\JsonException | \InvalidArgumentException | \UnexpectedValueException) {
                    ++$invalid;
                }
            }

            if (!feof($handle)) {
                throw new AnalyticsSpoolException('Unable to read an analytics spool segment.');
            }
        } finally {
            fclose($handle);
        }

        return ['events' => $events, 'invalid' => $invalid];
    }

    public function removeSegment(string $path): void
    {
        $this->assertSegmentPath($path);
        if (is_file($path) && !unlink($path)) {
            throw new AnalyticsSpoolException('Unable to remove a processed analytics spool segment.');
        }
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @param list<string> $lines */
    private function appendToShard(int $shard, array $lines, int $now): void
    {
        $lock = $this->openLock($shard);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new AnalyticsSpoolException('Unable to lock an analytics spool shard.');
            }

            foreach ($lines as $line) {
                $active = $this->activePath($shard);
                clearstatcache(true, $active);
                $currentBytes = is_file($active) ? filesize($active) : 0;
                if ($currentBytes === false) {
                    throw new AnalyticsSpoolException('Unable to inspect an analytics spool shard.');
                }

                if ($currentBytes > 0 && $currentBytes + \strlen($line) > $this->segmentBytes) {
                    $this->rotateUnderLock($shard, $now);
                    $currentBytes = 0;
                }

                if ($currentBytes === 0) {
                    $this->createOpenedMarker($shard, $now);
                }

                $this->appendLine($active, $line);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function appendLine(string $path, string $line): void
    {
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new AnalyticsSpoolException('Unable to open an analytics spool shard for writing.');
        }

        try {
            $this->protectFile($path);
            $remaining = $line;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if ($written === false || $written === 0) {
                    throw new AnalyticsSpoolException('Unable to append an analytics spool record.');
                }

                $remaining = substr($remaining, $written);
            }

            if (!fflush($handle)) {
                throw new AnalyticsSpoolException('Unable to flush an analytics spool record.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @phpstan-impure Filesystem state may change between the optimistic check and acquiring the shard lock. */
    private function activeIsDue(int $shard, int $now): bool
    {
        $active = $this->activePath($shard);
        if (!is_file($active)) {
            return false;
        }

        $size = filesize($active);
        if ($size === false || $size === 0) {
            return false;
        }

        if ($size >= $this->segmentBytes) {
            return true;
        }

        $openedAt = filemtime($this->openedPath($shard));
        return $openedAt !== false && $openedAt <= $now - $this->minimumSegmentAge;
    }

    private function rotateUnderLock(int $shard, int $now): bool
    {
        $active = $this->activePath($shard);
        if (!is_file($active) || filesize($active) === 0) {
            $this->removeOpenedMarker($shard);
            return false;
        }

        $segments = glob($this->directory . 'sealed-*.ndjson', GLOB_NOSORT);
        if ($segments !== false && \count($segments) >= $this->maxSealedSegments) {
            throw new AnalyticsSpoolException('The analytics spool has reached its disk quota.');
        }

        $sealed = $this->directory
            . 'sealed-' . sprintf('%010d', $now)
            . '-' . $shard
            . '-' . bin2hex(random_bytes(6))
            . '.ndjson';
        if (!rename($active, $sealed)) {
            throw new AnalyticsSpoolException('Unable to seal an analytics spool segment.');
        }

        $this->protectFile($sealed);
        $this->removeOpenedMarker($shard);
        return true;
    }

    /** @return resource */
    private function openLock(int $shard)
    {
        $path   = $this->directory . 'active-' . $shard . '.lock';
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new AnalyticsSpoolException('Unable to open an analytics spool lock.');
        }

        try {
            $this->protectFile($path);
        } catch (\Throwable $exception) {
            fclose($handle);
            throw $exception;
        }

        return $handle;
    }

    private function createOpenedMarker(int $shard, int $now): void
    {
        $path = $this->openedPath($shard);
        if (is_file($path)) {
            return;
        }

        if (!touch($path, $now)) {
            throw new AnalyticsSpoolException('Unable to create an analytics spool age marker.');
        }

        $this->protectFile($path);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new AnalyticsSpoolException('Unable to create the analytics spool directory.');
        }

        if (!chmod($this->directory, 0700)) {
            throw new AnalyticsSpoolException('Unable to protect the analytics spool directory.');
        }

        if (!is_writable($this->directory)) {
            throw new AnalyticsSpoolException('The analytics spool directory is not writable.');
        }
    }

    private function protectFile(string $path): void
    {
        if (!chmod($path, 0600)) {
            throw new AnalyticsSpoolException('Unable to protect an analytics spool file.');
        }
    }

    private function removeOpenedMarker(int $shard): void
    {
        $path = $this->openedPath($shard);
        if (is_file($path) && !unlink($path) && is_file($path)) {
            throw new AnalyticsSpoolException('Unable to remove an analytics spool age marker.');
        }
    }

    private function shard(string $eventId): int
    {
        // Seven hexadecimal digits fit into a signed 32-bit integer on every supported host.
        return (int)hexdec(substr($eventId, 0, 7)) % $this->shards;
    }

    private function activePath(int $shard): string
    {
        return $this->directory . 'active-' . $shard . '.ndjson';
    }

    private function openedPath(int $shard): string
    {
        return $this->directory . 'active-' . $shard . '.opened';
    }

    private function assertSegmentPath(string $path): void
    {
        if (dirname($path) . DIRECTORY_SEPARATOR !== $this->directory
            || preg_match('/^sealed-[0-9]{10}-[0-9]+-[a-f0-9]{12}\.ndjson$/D', basename($path)) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid analytics spool segment path.');
        }
    }
}
