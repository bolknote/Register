<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentRepository;
use Register\Content\ContentType;
use Register\Core\Comment\CommentHtml;
use Register\Core\Mail\CommentMailer;
use Register\Core\Model\User\UserProvider;
use Register\Url\ContentUrlGenerator;

/** Resolves current comment, content, subscriber and moderator data immediately before sending. */
final readonly class CommentMailDelivery
{
    public function __construct(
        private CommentRepository          $commentRepository,
        private CommentSubscriptionService $subscriptionService,
        private ContentRepository          $contentRepository,
        private ContentUrlGenerator        $contentUrlGenerator,
        private UserProvider               $userProvider,
        private CommentMailer              $commentMailer,
    ) {
    }

    public function subscriber(int $commentId, ContentType $contentType, string $recipientEmail): void
    {
        $comment = $this->comment($commentId, $contentType);
        if ($comment === null) {
            return;
        }

        if (!$comment->shown || $comment->deleted) {
            return;
        }

        $content = $this->contentRepository->find($comment->contentId);
        if ($content === null) {
            return;
        }

        if (!$content->commentsEnabled) {
            return;
        }

        $receiver = null;
        foreach ($this->subscriptionService->receivers($comment) as $candidate) {
            if (strcasecmp($candidate->email, $recipientEmail) === 0) {
                $receiver = $candidate;
                break;
            }
        }

        if ($receiver === null) {
            return;
        }

        if ($receiver->unsubscribeToken === null) {
            $this->commentMailer->mailToReplyRecipient(
                $receiver->name,
                $receiver->email,
                CommentHtml::plainText($comment->text),
                $content->title,
                $this->contentUrlGenerator->absolutePath($content->path) . '#comment-' . $comment->id,
                $comment->name,
            );
            return;
        }

        $unsubscribeLink = $this->contentUrlGenerator->rawAbsolutePath('/comment_unsubscribe', [
            'mail=' . urlencode($receiver->email),
            'id=' . $comment->contentId->value,
            'code=' . $receiver->unsubscribeToken,
        ]);
        $this->commentMailer->mailToSubscriber(
            $receiver->name,
            $receiver->email,
            CommentHtml::plainText($comment->text),
            $content->title,
            $this->contentUrlGenerator->absolutePath($content->path) . '#comment-' . $comment->id,
            $comment->name,
            $unsubscribeLink,
        );
    }

    public function moderator(
        int         $commentId,
        ContentType $contentType,
        string      $moderatorEmail,
        bool        $isPublished,
        string      $spamReportStatus,
    ): void {
        $comment = $this->comment($commentId, $contentType);
        if ($comment === null) {
            return;
        }

        if ($comment->deleted) {
            return;
        }

        $content = $this->contentRepository->find($comment->contentId);
        if ($content === null) {
            return;
        }

        foreach ($this->userProvider->getModerators([$moderatorEmail]) as $moderator) {
            $this->commentMailer->mailToModerator(
                $moderator->login,
                $moderator->email,
                CommentHtml::plainText($comment->text),
                $content->title,
                $this->contentUrlGenerator->absolutePath($content->path) . '#comment-' . $comment->id,
                $comment->name,
                $comment->email,
                $isPublished,
                $spamReportStatus,
            );
            return;
        }
    }

    private function comment(int $commentId, ContentType $contentType): ?Comment
    {
        $comment = $this->commentRepository->find($commentId);
        if ($comment === null) {
            return null;
        }

        return $comment->contentId->type === $contentType ? $comment : null;
    }
}
