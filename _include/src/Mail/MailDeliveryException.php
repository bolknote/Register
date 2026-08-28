<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

final class MailDeliveryException extends \RuntimeException
{
    public function __construct(
        string              $message,
        public readonly bool $permanent,
        public readonly ?int $smtpCode = null,
        ?\Throwable          $previous = null,
    ) {
        parent::__construct($message, $smtpCode ?? 0, $previous);
    }
}
