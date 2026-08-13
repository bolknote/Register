<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

/** Published content and its visible discussion, as shown on the dashboard. */
final readonly class ContentStatistics
{
    public function __construct(
        public int $contentCount,
        public int $commentCount,
    ) {
    }
}
