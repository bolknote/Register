<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Live;

use Register\Content\ContentId;

/** One durable invalidation consumed by the browser live-update endpoint. */
final readonly class LiveUpdate
{
    public function __construct(
        public int       $cursor,
        public string    $topic,
        public ContentId $contentId,
    ) {
    }
}
