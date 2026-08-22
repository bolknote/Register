<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Delivery;

use Register\Core\Queue\QueuePublisher;
use Register\Extension\activitypub\Infrastructure\DeliveryRepository;

/** A single generation-aware wake-up job; delivery truth remains in the module tables. */
final readonly class DeliveryQueue
{
    public const string CODE = 'register_activitypub_delivery';

    public const string JOB_ID = 'activitypub-delivery';

    public function __construct(
        private QueuePublisher     $queuePublisher,
        private DeliveryRepository $deliveryRepository,
    ) {
    }

    public function wake(?int $availableAt = null): void
    {
        $this->queuePublisher->publish(self::JOB_ID, self::CODE, availableAt: $availableAt);
    }

    public function wakeForNextPending(): void
    {
        $availableAt = $this->deliveryRepository->earliestPendingAt();
        if ($availableAt !== null) {
            $this->wake($availableAt);
        }
    }
}
