<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;

/** Sanitized comment input from an integration, without invented contact or network identity. */
final readonly class CommentImport
{
    public function __construct(
        public ContentId $contentId,
        public string    $name,
        public string    $text,
        public ?int      $parentId,
        public int       $createdAt,
    ) {
        if ($name === '' || mb_strlen($name) > 50) {
            throw new \InvalidArgumentException('An imported comment name must contain at most 50 characters.');
        }

        if ($text === '') {
            throw new \InvalidArgumentException('An imported comment cannot be empty.');
        }

        if ($parentId !== null && $parentId <= 0) {
            throw new \InvalidArgumentException('An imported parent comment identifier must be positive.');
        }

        if ($createdAt < 0) {
            throw new \InvalidArgumentException('An imported comment timestamp cannot be negative.');
        }
    }
}
