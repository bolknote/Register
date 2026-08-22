<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use Register\Module\Search\Admin\SearchIndexHealth;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\ScheduledMaintenanceTaskInterface;

/** Detects an abandoned partial index during normal hourly maintenance. */
final readonly class SearchIndexMaintenance implements ScheduledMaintenanceTaskInterface
{
    public function __construct(
        private SearchIndexHealth    $searchIndexHealth,
        private SearchIndexRepairer $searchIndexRepairer,
    ) {
    }

    #[\Override]
    public function schedule(int $now, QueueExecutionBudget $budget): void
    {
        $budget->checkpoint(0.5);
        if (!$this->searchIndexHealth->inspect()->repairRequired) {
            return;
        }

        $budget->checkpoint(0.02);
        $this->searchIndexRepairer->schedule($now + 1);
    }
}
