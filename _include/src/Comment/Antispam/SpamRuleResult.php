<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

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
