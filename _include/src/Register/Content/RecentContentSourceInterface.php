<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

/** A content source capable of selecting a bounded newest-first publication stream. */
interface RecentContentSourceInterface extends ContentSourceInterface
{
    /** @return iterable<int, ContentItem> */
    public function recent(int $limit): iterable;
}
