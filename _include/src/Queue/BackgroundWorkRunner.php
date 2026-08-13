<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

use Psr\Log\LoggerInterface;

final readonly class BackgroundWorkRunner
{
    public function __construct(
        private \PDO                $pdo,
        private QueueRunnerLock     $lock,
        private QueueConsumer       $consumer,
        private ScheduledMaintenance $maintenance,
        private LoggerInterface     $logger,
    ) {
    }

    /**
     * Runs a bounded slice and returns the number of attempted queue jobs.
     */
    public function run(float $maxSeconds = 5.0, int $maxJobs = 5): int
    {
        if ($maxSeconds <= 0.0) {
            throw new \InvalidArgumentException('Background work duration must be positive.');
        }

        if ($maxJobs < 0) {
            throw new \InvalidArgumentException('Background queue job limit must not be negative.');
        }

        if ($this->pdo->inTransaction()) {
            $this->logger->warning('Background work was skipped because a database transaction is still active.');
            return 0;
        }

        if (!$this->lock->acquire()) {
            return 0;
        }

        $deadline = hrtime(true) + (int)($maxSeconds * 1_000_000_000.0);
        $jobs     = 0;

        try {
            try {
                if (hrtime(true) < $deadline) {
                    $this->maintenance->runIfDue();
                }
            } catch (\Throwable $throwable) {
                $this->logger->error('Scheduled maintenance failed.', ['exception' => $throwable]);
            }

            while ($jobs < $maxJobs && hrtime(true) < $deadline) {
                try {
                    if (!$this->consumer->runQueue()) {
                        break;
                    }
                } catch (\Throwable $throwable) {
                    $this->logger->error('Background queue runner failed.', ['exception' => $throwable]);
                    break;
                }

                ++$jobs;
            }
        } finally {
            $this->lock->release();
        }

        return $jobs;
    }
}
