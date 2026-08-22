<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Author\AuthorProfile;

/** Complete public integration view of one canonical published content item. */
final readonly class ContentDetails
{
    /** @var list<Tag> */
    public array $tags;

    /**
     * @param array<mixed> $tags
     */
    public function __construct(
        public ContentItem    $content,
        public ?AuthorProfile $author,
        array                 $tags,
    ) {
        if ($content->authorId !== $author?->id) {
            throw new \InvalidArgumentException('Content details contain a mismatched author profile.');
        }

        $normalizedTags = [];
        foreach ($tags as $tag) {
            if (!$tag instanceof Tag) {
                throw new \InvalidArgumentException('Content details tags must be Tag objects.');
            }

            $normalizedTags[] = $tag;
        }

        $this->tags = $normalizedTags;
    }
}
