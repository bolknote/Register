<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model\Comment;

final readonly class CommentModerationContext
{
    public function __construct(
        public CommentModerator $moderator,
        public string           $targetType,
        public string           $returnPath,
    ) {
    }
}
