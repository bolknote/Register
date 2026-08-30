<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Auth\PublicAuthSchema;
use Register\Core\Pdo\DbLayer;

/** Keeps a verified external identity's first comment in moderation. */
final readonly class CommentPublicationTrustPolicy
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function requiresModeration(int $userId): bool
    {
        if ($userId <= 0) {
            return true;
        }

        $user = $this->dbLayer
            ->select('hide_comments', 'edit_comments', 'create_articles', 'edit_site', 'edit_users')
            ->from('users')
            ->where('id = :user_id')->setParameter('user_id', $userId)
            ->execute()
            ->fetchAssoc()
        ;
        if ($user === false) {
            return true;
        }

        foreach (['hide_comments', 'edit_comments', 'create_articles', 'edit_site', 'edit_users'] as $permission) {
            if ((bool)$user[$permission]) {
                return false;
            }
        }

        $isExternalIdentity = (int)$this->dbLayer
            ->select('COUNT(*)')
            ->from(PublicAuthSchema::IDENTITIES_TABLE)
            ->where('user_id = :user_id')->setParameter('user_id', $userId)
            ->execute()
            ->result()
            > 0
        ;
        if (!$isExternalIdentity) {
            return false;
        }

        // A mailbox or OAuth account proves identity ownership, not content quality.
        // One already-published, non-deleted comment is the trust boundary. Marking
        // a pending comment as ham publishes it and establishes that history.
        return (int)$this->dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where('user_id = :user_id')->setParameter('user_id', $userId)
            ->andWhere('shown = 1')
            ->andWhere('deleted = 0')
            ->execute()
            ->result()
            === 0
        ;
    }
}
