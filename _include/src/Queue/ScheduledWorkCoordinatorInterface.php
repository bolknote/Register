<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

interface ScheduledWorkCoordinatorInterface
{
    public function scheduleRequestWork(?int $now = null, ?QueueExecutionBudget $budget = null): void;

    public function hasDueWork(?int $now = null, ?QueueExecutionBudget $budget = null): bool;

    public function runIfDue(?int $now = null, ?QueueExecutionBudget $budget = null): bool;
}
