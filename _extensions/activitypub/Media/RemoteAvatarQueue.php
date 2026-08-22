<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Media;

use S2\Cms\Queue\QueuePublisher;
use s2_extensions\activitypub\Infrastructure\RemoteAvatarRepository;

/** A generation-aware wake-up job; the media table remains the source of truth. */
final readonly class RemoteAvatarQueue
{
    public const string CODE = 'register_activitypub_remote_avatar';

    public const string JOB_ID = 'activitypub-remote-avatar';

    public function __construct(
        private QueuePublisher         $queuePublisher,
        private RemoteAvatarRepository $repository,
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
