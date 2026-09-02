<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

/** A small disk-first queue for view counters that works on ordinary shared hosting. */
final readonly class ContentViewSpool
{
    private const int DEFAULT_SHARDS = 4;

    private const int DEFAULT_SEGMENT_BYTES = 131072;

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
            throw new \InvalidArgumentException('Invalid content-view spool limits.');
        }

        $this->directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
    }

    public function append(ContentViewIncrement $increment, ?int $now = null): void
    {
        $now ??= time();
        $line = json_encode([
            'content_type' => $increment->contentId->type->value,
            'content_id'   => $increment->contentId->value,
            'day'          => $increment->day,
            'views'        => $increment->views,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $this->ensureDirectory();
        $shard = random_int(0, $this->shards - 1);
        $lock = $this->openLock($shard);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new ContentViewSpoolException('Unable to lock a content-view spool shard.');
            }

            $active = $this->activePath($shard);
            clearstatcache(true, $active);
            $currentBytes = is_file($active) ? filesize($active) : 0;
            if ($currentBytes === false) {
                throw new ContentViewSpoolException('Unable to inspect a content-view spool shard.');
            }

            if ($currentBytes > 0 && $currentBytes + \strlen($line) > $this->segmentBytes) {
                $this->rotateUnderLock($shard, $now);
                $currentBytes = 0;
            }

            if ($currentBytes === 0) {
                $this->createOpenedMarker($shard, $now);
            }

            $this->appendLine($active, $line);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
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
                    throw new ContentViewSpoolException('Unable to lock a content-view spool shard.');
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

    /** @return array{increments: list<ContentViewIncrement>, invalid: int} */
    public function readSegment(string $path): array
    {
        $this->assertSegmentPath($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ContentViewSpoolException('Unable to open a content-view spool segment.');
        }

        $increments = [];
        $invalid = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                try {
                    $data = json_decode(trim($line), true, 4, JSON_THROW_ON_ERROR);
                    if (!\is_array($data)
                        || !\is_string($data['content_type'] ?? null)
                        || !\is_int($data['content_id'] ?? null)
                        || !\is_string($data['day'] ?? null)
                        || !\is_int($data['views'] ?? null)
                    ) {
                        throw new \UnexpectedValueException('A content-view spool record is invalid.');
                    }

                    $contentType = ContentType::tryFrom($data['content_type']);
                    if (!$contentType instanceof ContentType) {
                        throw new \UnexpectedValueException('A content-view spool type is invalid.');
                    }

                    $increments[] = new ContentViewIncrement(
                        new ContentId($contentType, $data['content_id']),
                        $data['day'],
                        $data['views'],
                    );
                } catch (\JsonException | \InvalidArgumentException | \UnexpectedValueException) {
                    ++$invalid;
                }
            }

            if (!feof($handle)) {
                throw new ContentViewSpoolException('Unable to read a content-view spool segment.');
            }
        } finally {
            fclose($handle);
        }

        return ['increments' => $increments, 'invalid' => $invalid];
    }

    public function segmentId(string $path): string
    {
        $this->assertSegmentPath($path);

        return substr(hash('sha256', basename($path)), 0, 24);
    }

    /**
     * Claims a sealed segment without waiting. The operating system releases flock() when a
     * PHP-FPM worker exits, so even a crashed worker cannot leave the queue permanently stuck.
     *
     * @return resource|null
     */
    public function acquireSegment(string $path)
    {
        $this->assertSegmentPath($path);
        if (!$this->segmentExists($path)) {
            return null;
        }

        $lockPath = $path . '.lock';
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new ContentViewSpoolException('Unable to open a content-view segment lock.');
        }

        try {
            $this->protectFile($lockPath);
            if (!flock($lock, LOCK_EX | LOCK_NB) || !$this->segmentExists($path)) {
                fclose($lock);
                return null;
            }
        } catch (\Throwable $throwable) {
            fclose($lock);
            throw $throwable;
        }

        return $lock;
    }

    public function releaseSegment(string $path, mixed $lock): void
    {
        $this->assertSegmentPath($path);
        if (!\is_resource($lock)) {
            throw new \InvalidArgumentException('Invalid content-view segment lock.');
        }

        flock($lock, LOCK_UN);
        fclose($lock);
        $lockPath = $path . '.lock';
        if (is_file($lockPath) && !unlink($lockPath) && is_file($lockPath)) {
            throw new ContentViewSpoolException('Unable to remove a content-view segment lock.');
        }
    }

    public function removeSegment(string $path): void
    {
        $this->assertSegmentPath($path);
        if (is_file($path) && !unlink($path)) {
            throw new ContentViewSpoolException('Unable to remove a processed content-view spool segment.');
        }
    }

    public function directory(): string
    {
        return $this->directory;
    }

    private function appendLine(string $path, string $line): void
    {
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new ContentViewSpoolException('Unable to open a content-view spool shard for writing.');
        }

        try {
            $this->protectFile($path);
            $remaining = $line;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if ($written === false || $written === 0) {
                    throw new ContentViewSpoolException('Unable to append a content-view spool record.');
                }

                $remaining = substr($remaining, $written);
            }

            if (!fflush($handle)) {
                throw new ContentViewSpoolException('Unable to flush a content-view spool record.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @phpstan-impure */
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

    /** @phpstan-impure */
    private function segmentExists(string $path): bool
    {
        clearstatcache(true, $path);
        return is_file($path);
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
            throw new ContentViewSpoolException('The content-view spool has reached its disk quota.');
        }

        $sealed = $this->directory
            . 'sealed-' . sprintf('%010d', $now)
            . '-' . $shard
            . '-' . bin2hex(random_bytes(6))
            . '.ndjson';
        if (!rename($active, $sealed)) {
            throw new ContentViewSpoolException('Unable to seal a content-view spool segment.');
        }

        $this->protectFile($sealed);
        $this->removeOpenedMarker($shard);
        return true;
    }

    /** @return resource */
    private function openLock(int $shard)
    {
        $path = $this->directory . 'active-' . $shard . '.lock';
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new ContentViewSpoolException('Unable to open a content-view spool lock.');
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
        if (!is_file($path) && !touch($path, $now)) {
            throw new ContentViewSpoolException('Unable to create a content-view spool age marker.');
        }

        $this->protectFile($path);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)
            && !mkdir($this->directory, 0700, true)
            && !is_dir($this->directory)
        ) {
            throw new ContentViewSpoolException('Unable to create the content-view spool directory.');
        }

        if (!chmod($this->directory, 0700) || !is_writable($this->directory)) {
            throw new ContentViewSpoolException('The content-view spool directory is not writable.');
        }
    }

    private function protectFile(string $path): void
    {
        if (!chmod($path, 0600)) {
            throw new ContentViewSpoolException('Unable to protect a content-view spool file.');
        }
    }

    private function removeOpenedMarker(int $shard): void
    {
        $path = $this->openedPath($shard);
        if (is_file($path) && !unlink($path) && is_file($path)) {
            throw new ContentViewSpoolException('Unable to remove a content-view spool age marker.');
        }
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
            throw new \InvalidArgumentException('Invalid content-view spool segment path.');
        }
    }
}
