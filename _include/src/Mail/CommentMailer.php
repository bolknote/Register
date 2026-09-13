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
            htmlBody: $this->htmlMessage('Email HTML pattern', [
                'name'        => $subscriberName,
                'author'      => $authorName,
                'title'       => $title,
                'url'         => $url,
                'text'        => $text,
                'unsubscribe' => $unsubscribeLink,
            ]),
            unsubscribeUrl: $unsubscribeLink,
        ));

        return true;
    }

    public function mailToReplyRecipient(
        string $recipientName,
        string $recipientEmail,
        string $text,
        string $title,
        string $url,
        string $authorName,
    ): bool {
        $messageTemplate = $this->translator->trans('Email reply pattern');
        $message = str_replace(
            ['<name>', '<author>', '<title>', '<url>', '<text>'],
            [$recipientName, $authorName, $title, $url, $text],
            $messageTemplate,
        );

        $this->mailer->send(new MailMessage(
            type: 'comment_reply',
            recipientEmail: $recipientEmail,
            recipientName: $recipientName,
            subject: \sprintf($this->translator->trans('Email subject'), $url),
            textBody: $message,
            htmlBody: $this->htmlMessage('Email reply HTML pattern', [
                'name'   => $recipientName,
                'author' => $authorName,
                'title'  => $title,
                'url'    => $url,
                'text'   => $text,
            ]),
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
            htmlBody: $this->htmlMessage('Email moderator HTML pattern', [
                'name'   => $moderatorName,
                'author' => $authorName,
                'title'  => $title,
                'url'    => $url,
                'text'   => $text,
                'status' => \sprintf(
                    $this->translator->trans($isPublished ? 'Comment check passed' : 'Comment check failed'),
                    $spamReportStatus,
                ),
            ]),
            replyToEmail: $authorEmail,
            replyToName: $authorName,
        ));

        return true;
    }

    /** @param array<string, string> $values */
    private function htmlMessage(string $templateKey, array $values): string
    {
        $placeholders = [];
        $replacements = [];
        foreach ($values as $name => $value) {
            $placeholders[] = '<' . $name . '>';
            $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $replacements[] = $name === 'text' ? nl2br($escaped) : $escaped;
        }

        return str_replace(
            $placeholders,
            $replacements,
            $this->translator->trans($templateKey),
        );
    }
}
