<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

/** Lets product modules publish durable hourly maintenance work without coupling it to S2. */
interface ScheduledMaintenanceTaskInterface
{
    public function schedule(int $now, QueueExecutionBudget $budget): void;
}
