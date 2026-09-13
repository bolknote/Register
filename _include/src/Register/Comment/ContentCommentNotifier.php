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
use Register\Core\Model\User\UserProvider;
use Register\Core\Pdo\DbLayerException;

/** Sends notifications and manages subscriptions for every Register content type. */
final readonly class ContentCommentNotifier
{
    public function __construct(
        private CommentRepository          $commentRepository,
        private CommentSubscriptionService $subscriptionService,
        private ContentRepository           $contentRepository,
        private CommentMailPublisher        $mailPublisher,
        private UserProvider                $userProvider,
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

        // Only a visible, still-live comment may enqueue mail. Hidden moderation items keep
        // sent=0 so an explicit approval can publish them and trigger this method exactly once.
        if ($comment->sent || !$comment->shown || $comment->deleted) {
            return;
        }

        $content = $this->contentRepository->find($comment->contentId);
        if (!$content instanceof ContentItem) {
            return;
        }

        if (!$content->commentsEnabled) {
            return;
        }

        $receivers = $this->subscriptionService->receivers($comment);
        $moderatorEmails = [];
        if ($receivers !== []) {
            foreach ($this->userProvider->getModerators() as $moderator) {
                $moderatorEmails[mb_strtolower($moderator->email)] = true;
            }
        }

        foreach ($receivers as $receiver) {
            // A moderator already receives the operational copy of this comment. Do not send
            // a second copy merely because the same address is subscribed or owns its parent.
            if (isset($moderatorEmails[mb_strtolower($receiver->email)])) {
                continue;
            }

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
