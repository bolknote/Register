<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;

final readonly class StoredActivityRepresentation
{
    public function __construct(
        public int     $id,
        public string  $publicId,
        public int     $actorId,
        public ?int    $objectId,
        public string  $type,
        public string  $visibility,
        public ActivityDeliveryIntent $deliveryIntent,
        public string  $serializedBody,
        public string  $bodyHash,
        public int     $publishedAt,
        public ?int    $localNoteId = null,
    ) {
    }
}
