<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

/** Transport-independent message data. Bodies and credentials are deliberately never logged. */
final readonly class MailMessage
{
    public function __construct(
        public string  $type,
        public string  $recipientEmail,
        public string  $recipientName,
        public string  $subject,
        public string  $textBody,
        public ?string $htmlBody = null,
        public ?string $replyToEmail = null,
        public string  $replyToName = '',
        public ?string $unsubscribeUrl = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $type) !== 1) {
            throw new \InvalidArgumentException('A mail message type is invalid.');
        }

        foreach ([$recipientName, $replyToName, $subject] as $headerValue) {
            if (str_contains($headerValue, "\r") || str_contains($headerValue, "\n") || str_contains($headerValue, "\0")) {
                throw new \InvalidArgumentException('Mail header values cannot contain control line breaks.');
            }
        }

        if ($unsubscribeUrl !== null) {
            $scheme = parse_url($unsubscribeUrl, PHP_URL_SCHEME);
            if (!\is_string($scheme)
                || !\in_array(strtolower($scheme), ['http', 'https'], true)
                || filter_var($unsubscribeUrl, FILTER_VALIDATE_URL) === false
                || str_contains($unsubscribeUrl, "\r")
                || str_contains($unsubscribeUrl, "\n")
                || str_contains($unsubscribeUrl, "\0")
            ) {
                throw new \InvalidArgumentException('A mail unsubscribe URL is invalid.');
            }
        }

        if ($textBody === '') {
            throw new \InvalidArgumentException('A mail message must have a text body.');
        }
    }
}
