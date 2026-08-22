<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Admin;

use S2\Cms\Model\PermissionChecker;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;

/** Central authorization policy for site-wide and author-owned federation controls. */
final readonly class ActivityPubAdminAccess
{
    public function __construct(
        private PermissionChecker    $permissionChecker,
        private LocalActorRepository $actorRepository,
    ) {
    }

    public function canAccess(): bool
    {
        return $this->permissionChecker->isGrantedAny(
            PermissionChecker::PERMISSION_CREATE_ARTICLES,
            PermissionChecker::PERMISSION_EDIT_SITE,
        );
    }

    public function canManageSite(): bool
    {
        return $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE);
    }

    public function currentAuthorId(): ?int
    {
        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_CREATE_ARTICLES)) {
            return null;
        }

        $userId = $this->permissionChecker->getUserId();

        return $userId !== null && $userId > 0 ? $userId : null;
    }

    public function canManageAuthor(int $authorId): bool
    {
        if ($authorId < 1) {
            return false;
        }

        return $this->canManageSite() || $this->currentAuthorId() === $authorId;
    }

    public function canManageActor(int $actorId): bool
    {
        if ($this->canManageSite()) {
            return true;
        }

        $authorId = $this->currentAuthorId();
        if ($actorId < 1 || $authorId === null) {
            return false;
        }

        return $this->actorRepository->findById($actorId)?->authorId === $authorId;
    }

    public function canPerform(string $operation, int $authorId, int $actorId): bool
    {
        if ($this->canManageSite()) {
            return true;
        }

        return match ($operation) {
            'author_save' => $this->canManageAuthor($authorId),
            'discover',
            'follow',
            'unfollow',
            'reply',
            'reply_update',
            'reply_delete',
            'like',
            'unlike',
            'emoji',
            'unemoji',
            'announce',
            'unannounce',
            'rotate_key',
            'change_handle',
            'move_actor' => $this->canManageActor($actorId),
            'setup_start',
            'setup_activate',
            'moderate',
            'push_queue',
            'pause',
            'resume',
            'decommission',
            'backfill_latest',
            'backfill_selected' => false,
            default => $this->canAccess(),
        };
    }
}
