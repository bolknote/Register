<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayerException;

/** Sends notifications and manages subscriptions for every Register content type. */
final readonly class ContentCommentNotifier
{
    public function __construct(
        private CommentRepository          $commentRepository,
        private CommentSubscriptionService $subscriptionService,
        private ContentRepository           $contentRepository,
        private CommentMailPublisher        $mailPublisher,
    ) {
    }

    /** @throws DbLayerException */
    public function notify(int $commentId, ?ContentType $expectedContentType = null): void
    {
        $comment = $this->commentRepository->find($commentId);
        if (
            !$comment instanceof Comment
            || ($expectedContentType instanceof ContentType && $comment->contentId->type !== $expectedContentType)
        ) {
            return;
        }

        // Visibility and delivery are independent states. We durably enqueue recipients first;
        // the caller can then publish without waiting for any external mail server.
        if ($comment->sent) {
            return;
        }

        $content = $this->contentRepository->find($comment->contentId);
        if (!$content instanceof ContentItem) {
            return;
        }

        if (!$content->commentsEnabled) {
            return;
        }

        foreach ($this->subscriptionService->receivers($comment->contentId, $comment->email) as $receiver) {
            $this->mailPublisher->subscriber(
                $comment->id,
                $comment->contentId->type,
                $receiver->email,
            );
        }

        $this->commentRepository->setSent($commentId, $comment->contentId->type, true);
    }

    /** @throws DbLayerException */
    public function unsubscribe(ContentId $contentId, string $email, string $code): bool
    {
        return $this->subscriptionService->unsubscribe($contentId, $email, $code);
    }
}
