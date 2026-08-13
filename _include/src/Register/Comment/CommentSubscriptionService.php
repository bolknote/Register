<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;
use S2\Cms\Comment\Antispam\SpamIdentityHasher;

final readonly class CommentSubscriptionService
{
    public function __construct(
        private CommentRepository  $commentRepository,
        private SpamIdentityHasher $hasher,
    ) {
    }

    /** @return list<CommentSubscriber> */
    public function receivers(ContentId $contentId, string $authorEmail): array
    {
        $byEmail = [];
        foreach ($this->commentRepository->findSubscribers($contentId, $authorEmail, false) as $comment) {
            $byEmail[$comment->email] = new CommentSubscriber(
                $comment->name,
                $comment->email,
                $this->token($comment),
            );
        }

        return array_values($byEmail);
    }

    public function unsubscribe(ContentId $contentId, string $email, string $token): bool
    {
        foreach ($this->commentRepository->findSubscribers($contentId, $email, true) as $comment) {
            if (hash_equals($this->token($comment), $token)) {
                $this->commentRepository->unsubscribe($contentId, $email);

                return true;
            }
        }

        return false;
    }

    private function token(Comment $comment): string
    {
        return $this->hasher->sign(
            'comment-unsubscribe',
            (string)$comment->contentId . "\0" . $comment->id . "\0" . $comment->email . "\0" . $comment->time,
        );
    }
}
