<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final readonly class LinkProbeStep
{
    private function __construct(
        public ?LinkProbeState  $nextState,
        public ?LinkProbeResult $result,
    ) {
    }

    public static function pending(LinkProbeState $nextState): self
    {
        return new self($nextState, null);
    }

    public static function complete(LinkProbeResult $result): self
    {
        return new self(null, $result);
    }
}
