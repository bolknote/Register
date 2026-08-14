<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

interface BackgroundWorkRunnerInterface
{
    /** Returns the number of attempted queue jobs. */
    public function run(float $maxSeconds = 5.0, int $maxJobs = 5): int;
}
