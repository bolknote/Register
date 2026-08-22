<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;

/** Announces a comment lifecycle transition inside the surrounding database transaction. */
final readonly class CommentChangedEvent
{
    public function __construct(
        public int                   $commentId,
        public ContentId             $contentId,
        public CommentChangeKind     $kind,
        public CommentMutationSource $source = CommentMutationSource::LOCAL,
    ) {
        if ($commentId <= 0) {
            throw new \InvalidArgumentException('A comment identifier must be positive.');
        }
    }
}
