<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller\Rss;

class FeedDto
{
    public function __construct(
        public string $title,
        public string $description,
        public string $link, // Absolute URL
        public string $language = 'en',
        public string $rssLink = '',
        public string $jsonFeedLink = '',
    ) {
    }
}
