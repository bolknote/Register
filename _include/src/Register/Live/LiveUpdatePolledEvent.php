<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Live;

use Symfony\Component\HttpFoundation\Request;

/** Announces a successful visible-page poll so optional subsystems can reuse its presence signal. */
final readonly class LiveUpdatePolledEvent
{
    public function __construct(
        public Request $request,
        public int     $occurredAt,
    ) {
    }
}
