<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller\Rss;

class FeedItemDto
{
    public function __construct(
        public string $title,
        public string $author,
        public string $link,
        public string $text,
        public int $time,
        public int $modifyTime,
        public string $summary = '',
        public string $image = '',
        /** @var list<string> */
        public array $tags = [],
    ) {
    }
}
