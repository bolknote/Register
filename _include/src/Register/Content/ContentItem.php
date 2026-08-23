<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

/** A storage-independent published post or page. */
final readonly class ContentItem
{
    public function __construct(
        public ContentId $id,
        public string    $title,
        public string    $body,
        public string    $path,
        public ?int      $publishedAt,
        public string    $keywords = '',
        public string    $description = '',
        public ?int      $updatedAt = null,
        public string    $author = '',
        public string    $series = '',
        public bool      $commentsEnabled = true,
        public string    $excerpt = '',
        public ?int      $authorId = null,
        public bool      $featured = false,
        public string    $socialImage = '',
    ) {
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('A content path must start with a slash.');
        }

        if ($authorId !== null && $authorId <= 0) {
            throw new \InvalidArgumentException('A content author identifier must be positive.');
        }
    }
}
