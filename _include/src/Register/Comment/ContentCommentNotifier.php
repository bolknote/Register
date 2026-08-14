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
use S2\Cms\Helper\StringHelper;
use S2\Cms\Mail\CommentMailer;
use S2\Cms\Pdo\DbLayerException;

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
    public function notify(int $commentId, ContentType $contentType): void
    {
        $comment = $this->commentRepository->findOfType($commentId, $contentType);
        if (!$comment instanceof Comment) {
            return;
        }

        if ($comment->shown || $comment->sent) {
            return;
        }

        $content = $this->contentRepository->find($comment->contentId);
        if (!$content instanceof ContentItem) {
            return;
        }

        if (!$content->commentsEnabled) {
            return;
        }

        $message = StringHelper::bbcodeToMail($comment->text);
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

        $this->commentRepository->setSent($commentId, $contentType, true);
    }

    /** @throws DbLayerException */
    public function unsubscribe(ContentId $contentId, string $email, string $code): bool
    {
        return $this->subscriptionService->unsubscribe($contentId, $email, $code);
    }
}
