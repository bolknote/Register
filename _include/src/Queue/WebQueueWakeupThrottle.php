<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

/** Avoids a database lease attempt on every cache hit; the global DB lease remains authoritative. */
final readonly class WebQueueWakeupThrottle
{
    /** @var \Closure(): float */
    private \Closure $clock;

    /** @param \Closure(): float|null $clock */
    public function __construct(private string $filename, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** @param callable(): mixed $work */
    public function run(int $cooldownSeconds, callable $work): void
    {
        if ($cooldownSeconds < 0) {
            throw new \InvalidArgumentException('Web queue cooldown must not be negative.');
        }

        if ($cooldownSeconds === 0) {
            $work();
            return;
        }

        $directory = \dirname($this->filename);
        if (is_link($this->filename) || (!is_dir($directory)
            && !register_call_without_warnings(static fn(): bool => mkdir($directory, 0700, true))
            && !is_dir($directory)
        )) {
            $work();
            return;
        }

        $handle = register_call_without_warnings(fn() => fopen($this->filename, 'c+b'));
        if ($handle === false) {
            // Loss of the local optimization must not prevent database-backed queue recovery.
            $work();
            return;
        }

        try {
            $wouldBlock = 0;
            if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if ($wouldBlock !== 1) {
                    $work();
                }

                return;
            }

            try {
                $stored = fread($handle, 64);
                $now = ($this->clock)();
                $lastRun = \is_string($stored) && is_numeric($stored) ? (float)$stored : 0.0;
                if ($lastRun > 0.0 && $lastRun <= $now && $now - $lastRun < $cooldownSeconds) {
                    return;
                }

                try {
                    $work();
                } finally {
                    // Measure cooldown from completion, matching QueueRunnerLease::release().
                    rewind($handle);
                    ftruncate($handle, 0);
                    fwrite($handle, sprintf('%.6F', ($this->clock)()));
                    fflush($handle);
                    register_call_without_warnings(fn(): bool => chmod($this->filename, 0600));
                }
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
