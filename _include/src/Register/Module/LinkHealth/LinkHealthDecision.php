<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final readonly class LinkHealthDecision
{
    public function __construct(
        public LinkHealthStatus $status,
        public int              $failureCount,
        public ?int             $nextCheckAt,
        public ?int             $lastSuccessAt,
        public bool             $lookupArchive,
    ) {
    }
}
