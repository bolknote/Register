<?php
/**
 * @copyright 2009-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

use Symfony\Contracts\Translation\TranslatorInterface;

/** Builds comment messages while the application mailer owns transport and sender identity. */
readonly class CommentMailer
{
    public function __construct(
        private TranslatorInterface        $translator,
        private ApplicationMailerInterface $mailer,
    ) {
    }

    public function mailToSubscriber(
        string $subscriberName,
        string $subscriberEmail,
        string $text,
        string $title,
        string $url,
        string $authorName,
        string $unsubscribeLink,
    ): bool {
        $messageTemplate = $this->translator->trans('Email pattern');
        $message = str_replace(
            ['<name>', '<author>', '<title>', '<url>', '<text>', '<unsubscribe>'],
            [$subscriberName, $authorName, $title, $url, $text, $unsubscribeLink],
            $messageTemplate,
        );

        $this->mailer->send(new MailMessage(
            type: 'comment_subscriber',
            recipientEmail: $subscriberEmail,
            recipientName: $subscriberName,
            subject: \sprintf($this->translator->trans('Email subject'), $url),
            textBody: $message,
            htmlBody: $this->plainTextHtml($message),
            unsubscribeUrl: $unsubscribeLink,
        ));

        return true;
    }

    public function mailToModerator(
        string $moderatorName,
        string $moderatorEmail,
        string $text,
        string $title,
        string $url,
        string $authorName,
        string $authorEmail,
        bool   $isPublished,
        string $spamReportStatus,
    ): bool {
        $messageTemplate = $this->translator->trans('Email moderator pattern');
        $message = str_replace(
            ['<name>', '<author>', '<title>', '<url>', '<text>', '<status>'],
            [
                $moderatorName,
                $authorName,
                $title,
                $url,
                $text,
                \sprintf(
                    $this->translator->trans($isPublished ? 'Comment check passed' : 'Comment check failed'),
                    $spamReportStatus,
                ),
            ],
            $messageTemplate,
        );

        $this->mailer->send(new MailMessage(
            type: 'comment_moderator',
            recipientEmail: $moderatorEmail,
            recipientName: $moderatorName,
            subject: \sprintf($this->translator->trans('Email subject'), $url),
            textBody: $message,
            htmlBody: $this->plainTextHtml($message),
            replyToEmail: $authorEmail,
            replyToName: $authorName,
        ));

        return true;
    }

    private function plainTextHtml(string $message): string
    {
        return '<div>' . nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')) . '</div>';
    }
}
