<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

/** Bounded JSONL storage for redacted request profiles. */
final readonly class QueryProfilerLog
{
    private const int MAX_LOG_BYTES = 10_000_000;

    public function __construct(private string $logFile)
    {
    }

    /** @param array<string, mixed> $record */
    public function append(array $record): void
    {
        $line = json_encode(
            $record,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        $this->withLockedFile(static function ($handle) use ($line): void {
            fseek($handle, 0, SEEK_END);
            $size = ftell($handle);
            if (\is_int($size) && $size >= self::MAX_LOG_BYTES) {
                ftruncate($handle, 0);
                rewind($handle);
            }

            if (fwrite($handle, $line) === false || !fflush($handle)) {
                throw new \RuntimeException('Unable to append the query profiler record.');
            }
        });
    }

    /** @return list<string> */
    public function lines(): array
    {
        if (!is_file($this->logFile) || is_link($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return \is_array($lines) ? $lines : [];
    }

    public function clear(): void
    {
        $this->withLockedFile(static function ($handle): void {
            if (!ftruncate($handle, 0) || !fflush($handle)) {
                throw new \RuntimeException('Unable to clear the query profiler log.');
            }
        });
    }

    /** @param callable(resource): void $operation */
    private function withLockedFile(callable $operation): void
    {
        $directory = dirname($this->logFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the query profiler log directory.');
        }

        if (is_link($this->logFile)) {
            throw new \RuntimeException('The query profiler log must not be a symbolic link.');
        }

        $handle = fopen($this->logFile, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the query profiler log.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the query profiler log.');
            }

            try {
                $operation($handle);
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }

        if (!chmod($this->logFile, 0600)) {
            throw new \RuntimeException('Unable to protect the query profiler log.');
        }
    }
}
