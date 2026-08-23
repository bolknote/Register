<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller\Comment;

use Register\Content\ContentType;

final readonly class PendingEmailComment
{
    public function __construct(
        public ContentType $contentType,
        public int         $targetId,
        public string      $name,
        public string      $email,
        public bool        $subscribed,
        public string      $text,
        public string      $ip,
        public ?int        $parentId,
        public string      $returnPath,
        public bool        $moderationRequired,
        public ?string     $visitorId,
    ) {
    }
}
