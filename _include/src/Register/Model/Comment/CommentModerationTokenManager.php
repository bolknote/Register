<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Model\Comment;

use Register\Content\ContentType;
use Register\Comment\Comment;
use Register\Comment\CommentDeletionState;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Model\Comment\CommentModerator;

final readonly class CommentModerationTokenManager
{
    public function __construct(private SpamIdentityHasher $hasher)
    {
    }

    public function issue(CommentModerator $moderator, ContentType $contentType, int $commentId): string
    {
        return $this->hasher->sign('comment-moderation', $this->payload($moderator, $contentType, $commentId));
    }

    public function isValid(
        string           $token,
        CommentModerator $moderator,
        ContentType      $contentType,
        int              $commentId,
    ): bool {
        if (preg_match('#^[0-9a-f]{64}$#D', $token) !== 1) {
            return false;
        }

        return hash_equals($this->issue($moderator, $contentType, $commentId), $token);
    }

    private function payload(CommentModerator $moderator, ContentType $contentType, int $commentId): string
    {
        return $moderator->login . "\0" . $moderator->sessionHash . "\0" . $contentType->value . "\0" . $commentId;
    }

    public function issueUndo(string $sessionScope, Comment $comment, CommentDeletionState $state, ?int $now = null): string
    {
        $payload = implode('.', [($now ?? time()) + 600, $state->revision, (int)$state->shown, (int)$state->sent, (int)$state->subscribed]);

        return $payload . '.' . $this->undoSignature($sessionScope, $comment, $payload);
    }

    public function readUndo(string $token, string $sessionScope, Comment $comment, ?int $now = null): ?CommentDeletionState
    {
        if (preg_match('/^(\d{1,11})\.(\d{1,11})\.([01])\.([01])\.([01])\.([a-f0-9]{64})$/D', $token, $parts) !== 1
            || (int)$parts[1] < ($now ?? time())
            || !$comment->deleted
            || (int)$parts[2] !== $comment->modifyTime
        ) {
            return null;
        }

        $payload = implode('.', array_slice($parts, 1, 5));
        if (!hash_equals($this->undoSignature($sessionScope, $comment, $payload), $parts[6])) {
            return null;
        }

        return new CommentDeletionState((int)$parts[2], $parts[3] === '1', $parts[4] === '1', $parts[5] === '1');
    }

    private function undoSignature(string $sessionScope, Comment $comment, string $payload): string
    {
        // No comment text or personal data travels in the token. Bind it to immutable content
        // as well as the session, so another record or an intervening edit cannot be restored.
        $fingerprint = hash('sha256', implode("\0", [
            (string)$comment->contentId, (string)$comment->id, (string)$comment->parentId,
            $comment->text, $comment->name, $comment->email,
        ]));

        return $this->hasher->sign('comment-undo', $sessionScope . "\0" . $fingerprint . "\0" . $payload);
    }
}
