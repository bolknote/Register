<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

/** The exact visibility and subscription state to restore after a moderator's deletion. */
final readonly class CommentDeletionState
{
    public function __construct(
        public int $revision,
        public bool $shown,
        public bool $sent,
        public bool $subscribed,
    ) {
    }
}
