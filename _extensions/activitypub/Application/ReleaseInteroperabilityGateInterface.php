<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

interface ReleaseInteroperabilityGateInterface
{
    public function check(): ActivationCheckResult;
}
