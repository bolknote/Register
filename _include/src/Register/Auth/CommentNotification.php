<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Content\ContentId;

final readonly class CommentNotification
{
    public function __construct(
        public int       $commentId,
        public ContentId $contentId,
    ) {
    }
}
