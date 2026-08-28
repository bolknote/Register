<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentType;
use Register\Core\Queue\QueuePublisher;

/** Publishes one idempotent queue item per recipient; tests may explicitly use direct delivery. */
final readonly class CommentMailPublisher
{
    public function __construct(
        private QueuePublisher       $queuePublisher,
        private CommentMailDelivery  $delivery,
        private bool                 $asynchronous = true,
    ) {
    }

    public function subscriber(int $commentId, ContentType $contentType, string $recipientEmail): void
    {
        if (!$this->asynchronous) {
            $this->delivery->subscriber($commentId, $contentType, $recipientEmail);
            return;
        }

        $this->queuePublisher->publishIfAbsent(
            $this->jobId('subscriber', $commentId, $contentType, $recipientEmail),
            CommentMailQueueHandler::SUBSCRIBER_CODE,
            [
                'comment_id'     => $commentId,
                'content_type'   => $contentType->value,
                'recipient_email' => mb_strtolower(trim($recipientEmail)),
            ],
        );
    }

    public function moderator(
        int         $commentId,
        ContentType $contentType,
        string      $moderatorEmail,
        bool        $isPublished,
        string      $spamReportStatus,
    ): void {
        if (!$this->asynchronous) {
            $this->delivery->moderator(
                $commentId,
                $contentType,
                $moderatorEmail,
                $isPublished,
                $spamReportStatus,
            );
            return;
        }

        $this->queuePublisher->publishIfAbsent(
            $this->jobId('moderator', $commentId, $contentType, $moderatorEmail),
            CommentMailQueueHandler::MODERATOR_CODE,
            [
                'comment_id'       => $commentId,
                'content_type'     => $contentType->value,
                'recipient_email'  => mb_strtolower(trim($moderatorEmail)),
                'is_published'     => $isPublished,
                'spam_status'      => mb_substr($spamReportStatus, 0, 80),
            ],
        );
    }

    private function jobId(string $kind, int $commentId, ContentType $contentType, string $email): string
    {
        return \sprintf(
            'comment-%s-%s-%d-%s',
            $kind,
            $contentType->value,
            $commentId,
            substr(hash('sha256', mb_strtolower(trim($email))), 0, 16),
        );
    }
}
