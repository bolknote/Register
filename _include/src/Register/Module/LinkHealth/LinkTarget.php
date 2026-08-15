<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final readonly class LinkTarget
{
    public function __construct(
        public int              $id,
        public string           $url,
        public LinkKind         $kind,
        public LinkHealthStatus $healthStatus,
        public ?int             $nextCheckAt,
    ) {
        if ($id < 1) {
            throw new \InvalidArgumentException('A link target identifier must be positive.');
        }
    }

    public function isDue(int $now): bool
    {
        return $this->kind === LinkKind::EXTERNAL
            && $this->healthStatus !== LinkHealthStatus::BROKEN
            && $this->healthStatus !== LinkHealthStatus::IGNORED
            && $this->healthStatus !== LinkHealthStatus::BLOCKED
            && $this->nextCheckAt !== null
            && $this->nextCheckAt <= $now;
    }
}
