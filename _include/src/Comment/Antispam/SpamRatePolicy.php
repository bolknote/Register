<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

final readonly class SpamRatePolicy
{
    public function __construct(
        public string $bucketType,
        public int    $limit,
        public int    $windowSeconds,
    ) {
    }
}
