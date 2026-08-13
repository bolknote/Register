<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

final readonly class SpamReputation
{
    public function __construct(
        public int $hamCount = 0,
        public int $spamCount = 0,
    ) {
    }
}
