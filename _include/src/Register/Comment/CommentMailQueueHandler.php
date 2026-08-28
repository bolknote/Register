<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentType;
use Register\Core\Helper\StringHelper;
use Register\Core\Mail\MailDeliveryException;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePermanentFailure;

final readonly class CommentMailQueueHandler implements QueueHandlerInterface
{
    public const string SUBSCRIBER_CODE = 'mail_comment_subscriber';

    public const string MODERATOR_CODE = 'mail_comment_moderator';

    public const array CODES = [self::SUBSCRIBER_CODE, self::MODERATOR_CODE];

    public function __construct(private CommentMailDelivery $delivery)
    {
    }

    #[\Override]
    public function codes(): array
    {
        return self::CODES;
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 2.0;
    }

    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        $budget->checkpoint();
        $commentId = $payload['comment_id'] ?? null;
        $contentType = ContentType::tryFrom((string)($payload['content_type'] ?? ''));
        $recipientEmail = $payload['recipient_email'] ?? null;
        if ($id === ''
            || !\is_int($commentId)
            || $commentId <= 0
            || $contentType === null
            || !\is_string($recipientEmail)
            || !StringHelper::isValidEmail($recipientEmail)
        ) {
            throw new QueuePermanentFailure('A comment mail queue payload is invalid.');
        }

        try {
            if ($code === self::SUBSCRIBER_CODE) {
                $this->delivery->subscriber($commentId, $contentType, $recipientEmail);
                return;
            }

            if ($code !== self::MODERATOR_CODE) {
                throw new QueuePermanentFailure('A comment mail queue code is invalid.');
            }

            $isPublished = $payload['is_published'] ?? null;
            $spamStatus = $payload['spam_status'] ?? null;
            if (!\is_bool($isPublished) || !\is_string($spamStatus) || mb_strlen($spamStatus) > 80) {
                throw new QueuePermanentFailure('A moderator mail queue payload is invalid.');
            }

            $this->delivery->moderator(
                $commentId,
                $contentType,
                $recipientEmail,
                $isPublished,
                $spamStatus,
            );
        } catch (MailDeliveryException $exception) {
            if ($exception->permanent) {
                throw new QueuePermanentFailure($exception->getMessage(), $exception->smtpCode ?? 0, $exception);
            }

            throw $exception;
        }
    }
}
