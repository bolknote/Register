<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Core\Config\StringProxy;
use Register\Core\Mail\ApplicationMailerInterface;
use Register\Core\Mail\MailMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Sends a short, single-purpose sign-in link without placing the token in logs. */
final readonly class PublicAuthMailer
{
    public function __construct(
        private TranslatorInterface        $translator,
        private StringProxy                $siteName,
        private ApplicationMailerInterface $mailer,
    ) {
    }

    public function sendMagicLink(string $email, string $url, bool $publishesComment): bool
    {
        $siteName = trim($this->siteName->get());
        $subject = $this->translator->trans($publishesComment
            ? 'Confirm comment by email subject'
            : 'Sign in by email subject', ['%site%' => $siteName]);
        $message = $this->translator->trans($publishesComment
            ? 'Confirm comment by email body'
            : 'Sign in by email body', [
                '%site%' => $siteName,
                '%url%'  => $url,
            ]);

        $this->mailer->send(new MailMessage(
            type: $publishesComment ? 'comment_verification' : 'auth_magic_link',
            recipientEmail: $email,
            recipientName: '',
            subject: $subject,
            textBody: $message,
            htmlBody: $this->htmlBody($message, $url),
        ));

        return true;
    }

    private function htmlBody(string $message, string $url): string
    {
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $escapedUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $linkedMessage = str_replace(
            $escapedUrl,
            '<a href="' . $escapedUrl . '">' . $escapedUrl . '</a>',
            $escapedMessage,
        );

        return '<div>' . nl2br($linkedMessage) . '</div>';
    }
}
