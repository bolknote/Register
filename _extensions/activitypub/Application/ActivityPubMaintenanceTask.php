<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Queue\ScheduledMaintenanceTaskInterface;

final readonly class ActivityPubMaintenanceTask implements ScheduledMaintenanceTaskInterface
{
    public function __construct(private QueuePublisher $queuePublisher)
    {
    }

    #[\Override]
    public function schedule(int $now, QueueExecutionBudget $budget): void
    {
        $budget->checkpoint(0.02);
        $this->queuePublisher->publishIfAbsent(
            ActivityPubMaintenanceQueueHandler::JOB_ID,
            ActivityPubMaintenanceQueueHandler::CODE,
            ['operation' => 0],
            $now + 1,
        );
    }
}
