<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Core\Model\AuthenticatedPublicUser;
use Register\Core\Pdo\DbLayer;

/** Computes the user's relevant unread comments and records read state. */
final readonly class CommentNotificationRepository
{
    public function __construct(
        private DbLayer             $dbLayer,
        private PublicAuthRepository $authRepository,
    ) {
    }

    public function countUnread(AuthenticatedPublicUser $user): int
    {
        $this->authRepository->ensureNotificationBaseline($user->id);

        return (int)$this->unreadQuery('COUNT(*)', $user)->result();
    }

    public function firstUnread(AuthenticatedPublicUser $user): ?CommentNotification
    {
        $this->authRepository->ensureNotificationBaseline($user->id);
        $row = $this->unreadQuery('c.id, c.content_type, c.content_id', $user, true)->fetchAssoc();
        if ($row === false) {
            return null;
        }

        $contentType = ContentType::tryFrom((string)$row['content_type']);
        if (!$contentType instanceof ContentType) {
            return null;
        }

        return new CommentNotification(
            (int)$row['id'],
            new ContentId($contentType, (int)$row['content_id']),
        );
    }

    public function markContentRead(AuthenticatedPublicUser $user, ContentId $contentId): void
    {
        $this->authRepository->ensureNotificationBaseline($user->id);
        $rows = $this->unreadQuery('c.id, c.content_type, c.content_id', $user)
            ->fetchAssocAll()
        ;
        $now = time();
        foreach ($rows as $row) {
            if ((string)$row['content_type'] !== $contentId->type->value
                || (int)$row['content_id'] !== $contentId->value
            ) {
                continue;
            }

            $this->dbLayer
                ->insert(PublicAuthSchema::NOTIFICATION_READS_TABLE)
                ->values([
                    'user_id'    => ':user_id',
                    'comment_id' => ':comment_id',
                    'read_at'    => ':read_at',
                ])
                ->onConflictDoNothing('user_id', 'comment_id')
                ->execute([
                    'user_id'    => $user->id,
                    'comment_id' => (int)$row['id'],
                    'read_at'    => $now,
                ])
            ;
        }
    }

    private function unreadQuery(
        string                  $selection,
        AuthenticatedPublicUser $user,
        bool                    $first = false,
    ): \Register\Core\Pdo\QueryResult {
        $prefix = $this->dbLayer->getPrefix();
        $sql = 'SELECT ' . $selection
            . ' FROM ' . $prefix . 'comments AS c'
            . ' INNER JOIN ' . $prefix . 'comment_notification_users AS nu ON nu.user_id = :user_id'
            . ' INNER JOIN ' . $prefix . 'content AS content_item'
            . ' ON content_item.id = c.content_id AND content_item.content_type = c.content_type'
            . ' LEFT JOIN ' . $prefix . 'comments AS parent_comment ON parent_comment.id = c.parent_id'
            . ' LEFT JOIN ' . $prefix . 'comment_notification_reads AS nr'
            . ' ON nr.user_id = :read_user_id AND nr.comment_id = c.id'
            . ' WHERE c.deleted = 0 AND c.id > nu.initial_comment_id'
            . ' AND nr.comment_id IS NULL'
            . ' AND (c.user_id IS NULL OR c.user_id <> :own_user_id)'
            . " AND (c.email = '' OR LOWER(c.email) <> LOWER(:own_email))"
            . ' AND ('
            . " (c.shown = 0 AND c.sent = 0 AND :include_pending = '1')"
            . ' OR (c.shown = 1 AND ('
            . ' content_item.author_id = :author_user_id'
            . ' OR parent_comment.user_id = :parent_user_id'
            . " OR (parent_comment.email <> '' AND LOWER(parent_comment.email) = LOWER(:parent_email))"
            . ' OR EXISTS (SELECT 1 FROM ' . $prefix . 'comments AS own_comment'
            . ' WHERE own_comment.content_type = c.content_type'
            . ' AND own_comment.content_id = c.content_id'
            . ' AND own_comment.id < c.id'
            . ' AND own_comment.shown = 1'
            . ' AND own_comment.deleted = 0'
            . ' AND own_comment.subscribed = 1'
            . ' AND (own_comment.user_id = :participant_user_id'
            . " OR (own_comment.email <> '' AND LOWER(own_comment.email) = LOWER(:participant_email))))))"
            . ')';
        if ($first) {
            $sql .= ' ORDER BY c.id ASC LIMIT 1';
        }

        return $this->dbLayer->query($sql, [
            'user_id'             => $user->id,
            'read_user_id'        => $user->id,
            'own_user_id'         => $user->id,
            'own_email'           => $user->email,
            'include_pending'     => $user->canHideComments || $user->canEditComments ? 1 : 0,
            'author_user_id'      => $user->id,
            'parent_user_id'      => $user->id,
            'parent_email'        => $user->email,
            'participant_user_id' => $user->id,
            'participant_email'   => $user->email,
        ]);
    }
}
