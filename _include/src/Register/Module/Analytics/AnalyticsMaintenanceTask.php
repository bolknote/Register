<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\ScheduledMaintenanceTaskInterface;

/** Removes expired unique-visitor fingerprints during the hourly maintenance pass. */
final readonly class AnalyticsMaintenanceTask implements ScheduledMaintenanceTaskInterface
{
    public function __construct(private AnalyticsRepository $repository)
    {
    }

    #[\Override]
    public function schedule(int $now, QueueExecutionBudget $budget): void
    {
        if ($now <= 0) {
            throw new \InvalidArgumentException('The analytics maintenance timestamp must be positive.');
        }

        $budget->checkpoint(0.05);
        $this->repository->forgetVisitorFingerprintsBefore(date('Y-m-d', $now));
    }
}
