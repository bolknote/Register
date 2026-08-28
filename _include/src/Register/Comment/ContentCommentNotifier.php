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
use Register\Url\ContentUrlGenerator;
use Register\Core\Comment\CommentHtml;
use Register\Core\Mail\CommentMailer;
use Register\Core\Pdo\DbLayerException;

/** Sends notifications and manages subscriptions for every Register content type. */
final readonly class ContentCommentNotifier
{
    public function __construct(
        private CommentRepository          $commentRepository,
        private CommentSubscriptionService $subscriptionService,
        private ContentRepository           $contentRepository,
        private ContentUrlGenerator         $contentUrlGenerator,
        private CommentMailer               $commentMailer,
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

        // Visibility and delivery are independent states. Moderation publishes first so readers
        // can immediately reply, then performs the potentially slow mail delivery.
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

        $message = CommentHtml::plainText($comment->text);
        $link    = $this->contentUrlGenerator->absolutePath($content->path);

        foreach ($this->subscriptionService->receivers($comment->contentId, $comment->email) as $receiver) {
            $unsubscribeLink = $this->contentUrlGenerator->rawAbsolutePath('/comment_unsubscribe', [
                'mail=' . urlencode($receiver->email),
                'id=' . $comment->contentId->value,
                'code=' . $receiver->unsubscribeToken,
            ]);

            $this->commentMailer->mailToSubscriber(
                $receiver->name,
                $receiver->email,
                $message,
                $content->title,
                $link,
                $comment->name,
                $unsubscribeLink,
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
