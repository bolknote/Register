<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

/** A monotonic cooperative deadline shared by the runner and the active handler. */
final readonly class QueueExecutionBudget
{
    private float $deadlineSeconds;

    /** @var \Closure(): float */
    private \Closure $clock;

    /** @param null|\Closure(): float $clock */
    public function __construct(float $maxSeconds, ?\Closure $clock = null)
    {
        if (!is_finite($maxSeconds) || $maxSeconds <= 0.0) {
            throw new \InvalidArgumentException('Queue execution budget must be positive and finite.');
        }

        $this->clock = $clock ?? static function (): float {
            [$seconds, $nanoseconds] = hrtime();
            return (float)$seconds + (float)$nanoseconds / 1_000_000_000.0;
        };
        $this->deadlineSeconds = ($this->clock)() + $maxSeconds;
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->deadlineSeconds - ($this->clock)());
    }

    public function canStart(float $requiredSeconds = 0.0): bool
    {
        if (!is_finite($requiredSeconds) || $requiredSeconds < 0.0) {
            throw new \InvalidArgumentException('Required queue execution time must be finite and non-negative.');
        }

        $remainingSeconds = $this->remainingSeconds();
        return $requiredSeconds === 0.0
            ? $remainingSeconds > 0.0
            : $remainingSeconds >= $requiredSeconds;
    }

    /**
     * Handlers call this immediately before every independently repeatable expensive step.
     */
    public function checkpoint(float $requiredSeconds = 0.0): void
    {
        if (!$this->canStart($requiredSeconds)) {
            throw new QueueTimeBudgetExceeded('The background execution time budget has been exhausted.');
        }
    }
}
