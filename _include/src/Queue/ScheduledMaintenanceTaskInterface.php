<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

/** Lets product modules add durable work to the hourly maintenance pass. */
interface ScheduledMaintenanceTaskInterface
{
    public function schedule(int $now, QueueExecutionBudget $budget): void;
}
