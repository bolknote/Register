<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use S2\Cms\Model\AuthenticatedPublicUser;

/** Issues a session-bound token for public-side post mutations. */
final readonly class PostInplaceTokenManager
{
    public function __construct(private SpamIdentityHasher $hasher)
    {
    }

    public function issue(AuthenticatedPublicUser $editor, int $postId): string
    {
        if ($postId <= 0) {
            throw new \InvalidArgumentException('A post id must be positive.');
        }

        return $this->hasher->sign('post-inplace', $this->payload($editor, $postId));
    }

    public function isValid(string $token, AuthenticatedPublicUser $editor, int $postId): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $token) === 1
            && hash_equals($this->issue($editor, $postId), $token);
    }

    private function payload(AuthenticatedPublicUser $editor, int $postId): string
    {
        return $editor->id . "\0" . $editor->login . "\0" . $editor->sessionHash . "\0" . $postId;
    }
}
