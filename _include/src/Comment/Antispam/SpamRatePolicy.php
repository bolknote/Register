<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

final readonly class SpamRatePolicy
{
    public function __construct(
        public string $bucketType,
        public int    $limit,
        public int    $windowSeconds,
    ) {
    }
}
