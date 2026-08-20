<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;

final readonly class Comment
{
    public function __construct(
        public int       $id,
        public ContentId $contentId,
        public ?int      $parentId,
        public ?int      $userpicId,
        public int       $time,
        public int       $modifyTime,
        public string    $ip,
        public string    $name,
        public string    $email,
        public bool      $showEmail,
        public bool      $subscribed,
        public bool      $shown,
        public bool      $deleted,
        public bool      $sent,
        public bool      $good,
        public string    $text,
    ) {
        if ($id <= 0) {
            throw new \InvalidArgumentException('A comment identifier must be a positive integer.');
        }

        if ($parentId !== null && $parentId <= 0) {
            throw new \InvalidArgumentException('A parent comment identifier must be a positive integer.');
        }

        if ($userpicId !== null && $userpicId <= 0) {
            throw new \InvalidArgumentException('A userpic identifier must be a positive integer.');
        }

        if ($time < 0) {
            throw new \InvalidArgumentException('A comment timestamp cannot be negative.');
        }

        if ($modifyTime < 0) {
            throw new \InvalidArgumentException('A comment modification timestamp cannot be negative.');
        }
    }
}
