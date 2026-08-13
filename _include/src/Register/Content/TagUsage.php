<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

final readonly class TagUsage
{
    public function __construct(
        public Tag $tag,
        public int $publishedContentCount,
    ) {
        if ($publishedContentCount < 0) {
            throw new \InvalidArgumentException('A tag usage count cannot be negative.');
        }
    }
}
