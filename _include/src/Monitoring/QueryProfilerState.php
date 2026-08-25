<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

/** File-backed, automatically expiring switch shared by public and administration requests. */
final readonly class QueryProfilerState
{
    public const int MIN_DURATION_SECONDS = 60;

    public const int MAX_DURATION_SECONDS = 900;

    public function __construct(private string $stateFile)
    {
    }

    /** @return array{active:bool, expires_at:int} */
    public function status(?int $now = null): array
    {
        $now ??= time();
        if ($now < 0 || !is_file($this->stateFile) || is_link($this->stateFile)) {
            return ['active' => false, 'expires_at' => 0];
        }

        $contents = file_get_contents($this->stateFile);
        if (!\is_string($contents)) {
            return ['active' => false, 'expires_at' => 0];
        }

        try {
            $state = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['active' => false, 'expires_at' => 0];
        }

        $expiresAt = \is_array($state) && \is_int($state['expires_at'] ?? null)
            ? $state['expires_at']
            : 0;

        return ['active' => $expiresAt > $now, 'expires_at' => max(0, $expiresAt)];
    }

    public function isActive(?int $now = null): bool
    {
        return $this->status($now)['active'];
    }

    public function start(int $durationSeconds, ?int $now = null): void
    {
        if ($durationSeconds < self::MIN_DURATION_SECONDS || $durationSeconds > self::MAX_DURATION_SECONDS) {
            throw new \InvalidArgumentException('Query profiler duration is outside the allowed range.');
        }

        $now ??= time();
        if ($now < 0) {
            throw new \InvalidArgumentException('Query profiler time must not be negative.');
        }

        $this->write($now + $durationSeconds);
    }

    public function stop(): void
    {
        $this->write(0);
    }

    private function write(int $expiresAt): void
    {
        $directory = dirname($this->stateFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the query profiler state directory.');
        }

        if (is_link($this->stateFile)) {
            throw new \RuntimeException('The query profiler state file must not be a symbolic link.');
        }

        $contents = json_encode([
            'version' => 1,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($this->stateFile, $contents, LOCK_EX) === false || !chmod($this->stateFile, 0600)) {
            throw new \RuntimeException('Unable to write the query profiler state.');
        }
    }
}
