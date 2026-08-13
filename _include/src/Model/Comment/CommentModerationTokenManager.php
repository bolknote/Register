<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model\Comment;

use S2\Cms\Comment\Antispam\SpamIdentityHasher;

final readonly class CommentModerationTokenManager
{
    public function __construct(private SpamIdentityHasher $hasher)
    {
    }

    public function issue(CommentModerator $moderator, string $targetType, int $commentId): string
    {
        return $this->hasher->sign('comment-moderation', $this->payload($moderator, $targetType, $commentId));
    }

    public function isValid(
        string           $token,
        CommentModerator $moderator,
        string           $targetType,
        int              $commentId,
    ): bool {
        if (preg_match('#^[0-9a-f]{64}$#D', $token) !== 1) {
            return false;
        }

        return hash_equals($this->issue($moderator, $targetType, $commentId), $token);
    }

    private function payload(CommentModerator $moderator, string $targetType, int $commentId): string
    {
        return $moderator->login . "\0" . $moderator->sessionHash . "\0" . $targetType . "\0" . $commentId;
    }
}
