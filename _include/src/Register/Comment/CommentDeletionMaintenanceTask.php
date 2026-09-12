<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\ScheduledMaintenanceTaskInterface;

final readonly class CommentDeletionMaintenanceTask implements ScheduledMaintenanceTaskInterface
{
    public function __construct(private CommentRepository $repository)
    {
    }

    #[\Override]
    public function schedule(int $now, QueueExecutionBudget $budget): void
    {
        $budget->checkpoint(0.05);
        // A full day provides headroom for the ten-minute Undo period and clock differences.
        $this->repository->purgeExpiredDeletions($now - 86400);
        $budget->checkpoint(0.05);
        $this->repository->anonymizeExpiredDeletions($now - 86400);
    }
}
