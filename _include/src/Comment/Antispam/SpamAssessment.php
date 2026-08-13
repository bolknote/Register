<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

final readonly class SpamAssessment
{
    /**
     * @param array<string, int> $reasons
     * @param list<string> $domainHashes
     */
    public function __construct(
        public int    $score,
        public array  $reasons,
        public string $textHash,
        public string $emailHash,
        public string $ipHash,
        public array  $domainHashes,
        public bool   $hardBlock = false,
    ) {
    }
}
