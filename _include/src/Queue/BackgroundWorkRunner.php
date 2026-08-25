<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

use Psr\Log\LoggerInterface;

final readonly class BackgroundWorkRunner implements BackgroundWorkRunnerInterface
{
    /** Keeps a killed 30-second FPM request from overlapping its replacement. */
    private const int MINIMUM_LEASE_SECONDS = 60;

    private const int LEASE_GRACE_SECONDS = 15;

    public function __construct(
        private \PDO                $pdo,
        private QueueRunnerLease    $lease,
        private QueueConsumer       $consumer,
        private ScheduledMaintenance $maintenance,
        private LoggerInterface     $logger,
    ) {
    }

    /**
     * Runs a bounded slice and returns the number of attempted queue jobs.
     */
    #[\Override]
    public function run(float $maxSeconds = 5.0, int $maxJobs = 5): int
    {
        if (!is_finite($maxSeconds) || $maxSeconds <= 0.0) {
            throw new \InvalidArgumentException('Background work duration must be positive and finite.');
        }

        if ($maxJobs < 0) {
            throw new \InvalidArgumentException('Background queue job limit must not be negative.');
        }

        if ($this->pdo->inTransaction()) {
            $this->logger->warning('Background work was skipped because a database transaction is still active.');
            return 0;
        }

        $budget = new QueueExecutionBudget($maxSeconds);
        $now = time();
        try {
            if (!$this->consumer->hasRunnableJob($now, $budget)
                && !$this->maintenance->hasDueWork($now, $budget)
            ) {
                return 0;
            }
        } catch (QueueTimeBudgetExceeded) {
            return 0;
        }

        $leaseSeconds = max(
            self::MINIMUM_LEASE_SECONDS,
            (int)ceil($maxSeconds) + self::LEASE_GRACE_SECONDS,
        );
        if (!$this->lease->acquire($leaseSeconds)) {
            return 0;
        }

        $jobs = 0;

        try {
            try {
                if ($budget->canStart(0.1)) {
                    $this->maintenance->scheduleRequestWork($now, $budget);
                    $this->maintenance->runIfDue($now, $budget);
                }
            } catch (QueueTimeBudgetExceeded) {
                // A custom sub-50ms handler may still fit the remaining slice.
            } catch (\Throwable $throwable) {
                $this->logger->error('Scheduled maintenance failed.', ['exception' => $throwable]);
            }

            while ($jobs < $maxJobs && $budget->canStart()) {
                try {
                    if (!$this->consumer->runQueue(budget: $budget)) {
                        break;
                    }
                } catch (\Throwable $throwable) {
                    $this->logger->error('Background queue runner failed.', ['exception' => $throwable]);
                    break;
                }

                ++$jobs;
            }
        } finally {
            $this->lease->release();
        }

        return $jobs;
    }
}
