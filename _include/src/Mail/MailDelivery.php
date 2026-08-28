<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

/** Confirms transport acceptance only; it does not claim inbox delivery. */
final readonly class MailDelivery
{
    public function __construct(
        public string $transport,
        public string $messageId,
        public float  $durationMs,
    ) {
    }
}
