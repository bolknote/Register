<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

final readonly class SpamRuleResult
{
    /** @param array<string, int> $reasons */
    public function __construct(
        public int   $score,
        public array $reasons,
        public bool  $hardBlock,
    ) {
    }
}
