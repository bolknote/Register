<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Admin;

final readonly class SearchIndexHealthStatus
{
    public function __construct(
        public bool $available,
        public int  $expectedDocuments,
        public int  $indexedDocuments,
        public int  $pendingUpdates,
        public int  $mismatchedDocuments,
        public bool $repairRequired,
    ) {
    }

    public function isUpdating(): bool
    {
        return $this->available && !$this->repairRequired && $this->pendingUpdates > 0;
    }

    public function isCurrent(): bool
    {
        return $this->available && !$this->repairRequired && $this->pendingUpdates === 0;
    }
}
