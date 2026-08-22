<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Model\Comment;

use Register\Content\ContentType;

final readonly class CommentModerationContext
{
    public function __construct(
        public CommentModerator $moderator,
        public ContentType      $contentType,
        public string           $returnPath,
    ) {
    }
}
