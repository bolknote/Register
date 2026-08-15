<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

final readonly class ContentEditorialStatistics
{
    public function __construct(
        public int $draftCount,
        public int $scheduledCount,
        public int $overdueCount,
        public ?int $nextScheduledAt,
    ) {
    }
}
