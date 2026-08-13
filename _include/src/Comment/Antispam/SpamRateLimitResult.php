<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

final readonly class SpamRateLimitResult
{
    /** @param list<string> $violations */
    public function __construct(
        public array $violations = [],
        public bool  $available = true,
    ) {
    }

    public function isLimited(): bool
    {
        return $this->violations !== [];
    }
}
