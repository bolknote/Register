<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Core\Config\StringProxy;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Sends a short, single-purpose sign-in link without placing the token in logs. */
final readonly class PublicAuthMailer
{
    /** @var \Closure(string, string, string, string): bool */
    private \Closure $sender;

    /** @param (callable(string, string, string, string): bool)|null $sender */
    public function __construct(
        private TranslatorInterface $translator,
        private StringProxy         $siteName,
        private StringProxy         $webmasterName,
        private StringProxy         $webmasterEmail,
        ?callable                            $sender = null,
    ) {
        $this->sender = $sender !== null
            ? $sender(...)
            : mail(...);
    }

    public function sendMagicLink(string $email, string $url, bool $publishesComment): bool
    {
        $siteName = trim($this->siteName->get());
        $subjectText = $this->translator->trans($publishesComment
            ? 'Confirm comment by email subject'
            : 'Sign in by email subject', ['%site%' => $siteName]);
        $message = $this->translator->trans($publishesComment
            ? 'Confirm comment by email body'
            : 'Sign in by email body', [
                '%site%' => $siteName,
                '%url%'  => $url,
            ]);
        $message = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $message);

        $subject = '=?UTF-8?B?' . base64_encode($subjectText) . '?=';
        $fromEmail = trim($this->webmasterEmail->get());
        if ($fromEmail === '') {
            $fromEmail = 'noreply@localhost';
        }

        $fromName = trim($this->webmasterName->get());
        $from = $fromName === ''
            ? $fromEmail
            : '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
        $headers = implode("\r\n", [
            'From: ' . $from,
            'Date: ' . gmdate('r'),
            'MIME-Version: 1.0',
            'Content-transfer-encoding: 8bit',
            'Content-type: text/plain; charset=utf-8',
            'X-Mailer: Register Mailer',
        ]);

        return ($this->sender)($email, $subject, $message, $headers);
    }
}
