<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Core\Queue\OpportunisticMaintenanceTaskInterface;
use Register\Core\Queue\QueueExecutionBudget;

/** Drains a bounded number of content-view segments after a response has been sent. */
final readonly class ContentViewMaintenanceTask implements OpportunisticMaintenanceTaskInterface
{
    private const int SEGMENTS_PER_SLICE = 4;

    public function __construct(
        private ContentViewSpool          $spool,
        private ContentViewSpoolProcessor $processor,
    ) {
    }

    #[\Override]
    public function schedule(int $now, QueueExecutionBudget $budget): void
    {
        $this->runIfDue($now, $budget);
    }

    #[\Override]
    public function hasDueWork(int $now, QueueExecutionBudget $budget): bool
    {
        if ($now <= 0) {
            throw new \InvalidArgumentException('The content-view maintenance timestamp must be positive.');
        }

        $budget->checkpoint(0.005);
        return $this->processor->canProcess() && $this->spool->hasDueWork($now);
    }

    #[\Override]
    public function runIfDue(int $now, QueueExecutionBudget $budget): bool
    {
        if (!$this->hasDueWork($now, $budget)) {
            return false;
        }

        $budget->checkpoint(0.02);
        $this->spool->sealDue($now);
        $completed = false;
        foreach ($this->spool->sealedSegments(self::SEGMENTS_PER_SLICE) as $segment) {
            $budget->checkpoint(0.05);
            $this->processor->process($segment);
            $completed = true;
        }

        return $completed;
    }
}
