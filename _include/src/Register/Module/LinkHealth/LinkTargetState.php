<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final readonly class LinkTargetState
{
    public function __construct(
        public int              $id,
        public string           $url,
        public LinkKind         $kind,
        public LinkHealthStatus $healthStatus,
        public int              $failureCount,
        public ?int             $nextCheckAt,
        public int              $lastSeenAt,
        public ?int             $lastSuccessAt,
        public ArchiveStatus    $archiveStatus,
        public ?string          $archiveUrl,
    ) {
    }
}
