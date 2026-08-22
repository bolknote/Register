<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use s2_extensions\activitypub\Domain\ActivityDeliveryIntent;

final readonly class NewStoredActivity
{
    public function __construct(
        public string                 $publicId,
        public int                    $actorId,
        public ?int                   $objectId,
        public string                 $type,
        public string                 $visibility,
        public ActivityDeliveryIntent $deliveryIntent,
        public string                 $deduplicationKey,
        public string                 $serializedBody,
        public string                 $bodyHash,
        public int                    $publishedAt,
        public int                    $createdAt,
        public ?int                   $localNoteId = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1
            || $actorId < 1
            || ($objectId !== null && $objectId < 1)
            || ($localNoteId !== null && $localNoteId < 1)
            || ($objectId !== null && $localNoteId !== null)
            || !\in_array($type, [
                'Create',
                'Update',
                'Delete',
                'Follow',
                'Accept',
                'Reject',
                'Undo',
                'Like',
                'EmojiReact',
                'Announce',
                'Block',
                'Flag',
                'Move',
                'Add',
                'Remove',
            ], true)
            || !\in_array($visibility, ['public', 'unlisted', 'direct'], true)
            || $deduplicationKey === ''
            || \strlen($deduplicationKey) > 128
            || preg_match('/^[a-f0-9]{64}$/D', $bodyHash) !== 1
            || $publishedAt < 1
            || $createdAt < 1
        ) {
            throw new \InvalidArgumentException('The new local ActivityPub activity record is invalid.');
        }
    }
}
