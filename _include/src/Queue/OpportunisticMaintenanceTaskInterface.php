<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

/** Runs small, request-driven maintenance slices in addition to the hourly pass. */
interface OpportunisticMaintenanceTaskInterface extends ScheduledMaintenanceTaskInterface
{
    public function hasDueWork(int $now, QueueExecutionBudget $budget): bool;

    /** @return bool Whether any work was completed. */
    public function runIfDue(int $now, QueueExecutionBudget $budget): bool;
}
