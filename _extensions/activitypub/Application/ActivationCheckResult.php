<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

final readonly class ActivationCheckResult
{
    public function __construct(
        public ActivationReadinessCheck $check,
        public bool                     $passed,
        public string                   $detail,
    ) {
    }
}
