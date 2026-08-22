<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Delivery;

use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Infrastructure\DeliveryRepository;
use Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation;

/** Materializes current follower endpoints in the activity's publication transaction. */
final readonly class DeliveryPlanner
{
    public function __construct(
        private DeliveryRepository $repository,
        private DeliveryQueue      $queue,
    ) {
    }

    public function plan(StoredActivityRepresentation $activity, int $now): int
    {
        return $this->planForActors($activity, [$activity->actorId], $now);
    }

    /** @param non-empty-list<int> $localActorIds */
    public function planForActors(StoredActivityRepresentation $activity, array $localActorIds, int $now): int
    {
        if ($activity->deliveryIntent === ActivityDeliveryIntent::NONE) {
            return 0;
        }

        if ($activity->deliveryIntent !== ActivityDeliveryIntent::FOLLOWERS) {
            throw new \InvalidArgumentException('An explicitly addressed ActivityPub activity requires planDirect().');
        }

        $inserted = $this->repository->planFollowers($activity, $now, $localActorIds);
        $this->queue->wakeForNextPending();

        return $inserted;
    }

    public function planDirect(
        StoredActivityRepresentation $activity,
        string                       $inboxUrl,
        string                       $recipient,
        int                          $now,
    ): int {
        return $this->planDirectRecipients($activity, $inboxUrl, [$recipient], $now);
    }

    /** @param non-empty-list<string> $recipients */
    public function planDirectRecipients(
        StoredActivityRepresentation $activity,
        string                       $inboxUrl,
        array                        $recipients,
        int                          $now,
    ): int {
        $inserted = $this->repository->planDirect($activity, $inboxUrl, $recipients, $now);
        $this->queue->wakeForNextPending();

        return $inserted;
    }
}
