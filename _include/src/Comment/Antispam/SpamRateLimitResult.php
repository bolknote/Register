<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

final readonly class SpamRateLimitResult
{
    /** @param list<string> $violations */
    public function __construct(
        public array $violations = [],
        public bool  $available = true,
        public int   $retryAfter = 0,
    ) {
    }

    public function isLimited(): bool
    {
        return $this->violations !== [];
    }
}
