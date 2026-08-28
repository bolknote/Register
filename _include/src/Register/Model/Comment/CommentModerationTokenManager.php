<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Model\Comment;

use Register\Content\ContentType;
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
}
