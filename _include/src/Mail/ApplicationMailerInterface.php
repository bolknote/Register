<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

interface ApplicationMailerInterface
{
    /** @throws MailDeliveryException */
    public function send(MailMessage $message): MailDelivery;
}
