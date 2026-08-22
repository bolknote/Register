<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Inbox;

use Register\Core\Queue\QueuePublisher;
use Register\Extension\activitypub\Infrastructure\InboxRepository;

final readonly class InboxQueue
{
    public const string CODE = 'register_activitypub_inbox';

    public const string JOB_ID = 'activitypub-inbox';

    public function __construct(
        private QueuePublisher   $queuePublisher,
        private InboxRepository $repository,
    ) {
    }

    public function wake(?int $availableAt = null): void
    {
        $this->queuePublisher->publish(self::JOB_ID, self::CODE, availableAt: $availableAt);
    }

    public function wakeForNextPending(): void
    {
        $availableAt = $this->repository->earliestPendingAt();
        if ($availableAt !== null) {
            $this->wake($availableAt);
        }
    }
}
