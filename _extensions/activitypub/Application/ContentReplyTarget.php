<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Infrastructure\StoredObjectRepresentation;

/** Identifies local published content, optionally through one imported parent comment. */
final readonly class ContentReplyTarget
{
    public function __construct(
        public StoredObjectRepresentation $object,
        public ?int                       $parentCommentId,
    ) {
        if ($this->parentCommentId !== null && $this->parentCommentId < 1) {
            throw new \InvalidArgumentException('An ActivityPub parent comment identifier must be positive.');
        }
    }
}
