<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;
use Register\Core\Comment\Antispam\SpamIdentityHasher;

final readonly class CommentSubscriptionService
{
    public function __construct(
        private CommentRepository  $commentRepository,
        private SpamIdentityHasher $hasher,
    ) {
    }

    /** @return list<CommentSubscriber> */
    public function receivers(Comment $comment): array
    {
        $byEmail = [];
        foreach ($this->commentRepository->findSubscribers($comment->contentId, $comment->email, false) as $subscriber) {
            $byEmail[mb_strtolower($subscriber->email)] = new CommentSubscriber(
                $subscriber->name,
                $subscriber->email,
                $this->token($subscriber),
            );
        }

        if ($comment->parentId !== null) {
            $parent = $this->commentRepository->find($comment->parentId);
            if ($parent instanceof Comment) {
                $parentEmail = $parent->email;
                $emailKey = mb_strtolower($parentEmail);
                if ($parent->userId !== null
                    && $parent->shown
                    && !$parent->deleted
                    && $parentEmail !== ''
                    && strcasecmp($parentEmail, $comment->email) !== 0
                    && !isset($byEmail[$emailKey])
                ) {
                    $byEmail[$emailKey] = new CommentSubscriber(
                        $parent->name,
                        $parentEmail,
                        null,
                    );
                }
            }
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
