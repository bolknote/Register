<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

interface BackgroundWorkRunnerInterface
{
    /** Returns the number of attempted queue jobs. */
    public function run(float $maxSeconds = 5.0, int $maxJobs = 5): int;
}
