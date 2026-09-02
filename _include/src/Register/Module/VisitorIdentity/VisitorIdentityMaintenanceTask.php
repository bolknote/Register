<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\ScheduledMaintenanceTaskInterface;

/** Gradually removes legacy visitor rows that have never produced a durable interaction. */
final readonly class VisitorIdentityMaintenanceTask implements ScheduledMaintenanceTaskInterface
{
    private const int RETENTION_SECONDS = 30 * 86400;

    private const int ROWS_PER_PASS = 100;

    public function __construct(private VisitorIdentityRepository $repository)
    {
    }

    #[\Override]
    public function schedule(int $now, QueueExecutionBudget $budget): void
    {
        if ($now <= 0) {
            throw new \InvalidArgumentException('The visitor maintenance timestamp must be positive.');
        }

        $budget->checkpoint(0.05);
        $this->repository->purgeUnreferencedBefore($now - self::RETENTION_SECONDS, self::ROWS_PER_PASS);
    }
}
