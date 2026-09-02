<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

/** One or more anonymous views of a content item on a UTC calendar day. */
final readonly class ContentViewIncrement
{
    public function __construct(
        public ContentId $contentId,
        public string    $day,
        public int       $views = 1,
    ) {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $match) !== 1
            || !checkdate((int)$match[2], (int)$match[3], (int)$match[1])
        ) {
            throw new \InvalidArgumentException('A content-view day must be a valid ISO date.');
        }

        if ($views < 1) {
            throw new \InvalidArgumentException('A content-view increment must be positive.');
        }
    }

    public function key(): string
    {
        return (string)$this->contentId . ':' . $this->day;
    }
}
