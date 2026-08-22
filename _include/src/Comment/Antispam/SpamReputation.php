<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

final readonly class SpamReputation
{
    public function __construct(
        public int $hamCount = 0,
        public int $spamCount = 0,
    ) {
    }
}
