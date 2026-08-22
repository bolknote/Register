<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use s2_extensions\activitypub\Domain\ContentBackfillMode;
use s2_extensions\activitypub\Domain\ContentBackfillState;

final readonly class ContentBackfillJob
{
    public function __construct(
        public string               $id,
        public ContentBackfillMode  $mode,
        public ContentBackfillState $state,
        public int                  $requestedBy,
        public int                  $totalCount,
        public int                  $processedCount,
        public int                  $projectedCount,
        public int                  $skippedCount,
        public int                  $failedCount,
        public int                  $createdAt,
        public ?int                 $startedAt,
        public ?int                 $completedAt,
        public int                  $updatedAt,
    ) {
    }
}
