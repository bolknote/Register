<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Live\LiveUpdateRepository;
use Register\Core\Pdo\DbLayer;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class CommentRepository
{
    public function __construct(
        private DbLayer              $dbLayer,
        private LiveUpdateRepository $liveUpdateRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function save(
        ContentId $contentId,
        string    $name,
        string    $email,
        bool      $subscribed,
        string    $text,
        string    $ip,
        ?int      $parentId,
        ?int      $userId = null,
        ?int      $time = null,
        CommentMutationSource $source = CommentMutationSource::LOCAL,
        ?string   $visitorId = null,
        ?int      $modifyTime = null,
    ): int {
        if ($parentId !== null && !$this->isValidParent(
            $contentId,
            $parentId,
            $source === CommentMutationSource::IMPORTED,
        )) {
            throw new \InvalidArgumentException('The parent comment does not belong to the commented content.');
        }

        $userpicId = $this->latestUserpicId($userId);

        $this->dbLayer
            ->insert(CommentSchema::TABLE_NAME)
            ->values([
                'content_type' => ':content_type',
                'content_id'   => ':content_id',
                'parent_id'    => ':parent_id',
                'user_id'      => ':user_id',
                'visitor_id'   => ':visitor_id',
                'userpic_id'   => ':userpic_id',
                'time'         => ':time',
                'modify_time'  => ':modify_time',
                'ip'           => ':ip',
                'nick'         => ':nick',
                'email'        => ':email',
                'subscribed'   => ':subscribed',
                'shown'        => '0',
                'deleted'      => '0',
                'sent'         => '0',
                'good'         => '0',
                'text'         => ':text',
            ])
            ->execute([
                'content_type' => $contentId->type->value,
                'content_id'   => $contentId->value,
                'parent_id'    => $parentId,
                'user_id'      => $userId,
                'visitor_id'   => $visitorId,
                'userpic_id'   => $userpicId,
                'time'         => $time ?? time(),
                'modify_time'  => $modifyTime ?? 0,
                'ip'           => $ip,
                'nick'         => $name,
                'email'        => $email,
                'subscribed'   => $subscribed ? 1 : 0,
                'text'         => $text,
            ])
        ;

        $commentId = (int)$this->dbLayer->insertId();
        $this->liveUpdateRepository->publishComments($contentId);
        $this->dispatch($commentId, $contentId, CommentChangeKind::CREATED, $source);

        return $commentId;
    }

    /** Reconciles mutable fields of a comment owned by an external integration. */
    public function synchronizeImported(int $commentId, CommentImport $comment): bool
    {
        if ($commentId <= 0
            || ($comment->parentId !== null && !$this->isValidParent($comment->contentId, $comment->parentId, true))
        ) {
            throw new \InvalidArgumentException('The imported comment parent is invalid.');
        }

        $current = $this->find($commentId);
        if (!$current instanceof Comment || !$current->contentId->equals($comment->contentId)) {
            throw new \DomainException('The imported comment does not belong to the requested content item.');
        }

        $updated = $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('parent_id', ':parent_id')->setParameter('parent_id', $comment->parentId)
            ->set('user_id', ':user_id')->setParameter('user_id', $comment->userId)
            ->set('userpic_id', ':userpic_id')->setParameter('userpic_id', $this->latestUserpicId($comment->userId))
            ->set('time', ':time')->setParameter('time', $comment->createdAt)
            ->set('modify_time', ':modify_time')->setParameter('modify_time', $comment->modifiedAt ?? 0)
            ->set('nick', ':nick')->setParameter('nick', $comment->name)
            ->set('text', ':text')->setParameter('text', $comment->text)
            ->where('id = :id')->setParameter('id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $comment->contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $comment->contentId->value)
            ->andWhere('deleted = 0')
            ->execute()
            ->affectedRows() > 0
        ;
        if ($updated) {
            $this->liveUpdateRepository->publishComments($comment->contentId);
            $this->dispatch(
                $commentId,
                $comment->contentId,
                CommentChangeKind::EDITED,
                CommentMutationSource::IMPORTED,
            );
        }

        return $updated;
    }

    private function latestUserpicId(?int $userId): ?int
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $row = $this->dbLayer
            ->select('userpic_id')
            ->from(\Register\Core\Model\UserpicSchema::USER_LINK_TABLE_NAME)
            ->where('user_id = :user_id')->setParameter('user_id', $userId)
            ->orderBy('userpic_id DESC')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;

        return $row === false ? null : (int)$row['userpic_id'];
    }

    public function find(int $commentId): ?Comment
    {
        $row = $this->dbLayer
            ->select('*')
            ->from(CommentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $commentId)
            ->execute()
            ->fetchAssoc()
        ;

        return $row === false ? null : $this->hydrate($row);
    }

    public function findOfType(int $commentId, ContentType $contentType): ?Comment
    {
        $comment = $this->find($commentId);
        if (!$comment instanceof Comment) {
            return null;
        }

        return $comment->contentId->type === $contentType ? $comment : null;
    }

    /** @return list<Comment> */
    public function findForContent(ContentId $contentId): array
    {
        $rows = $this->dbLayer
            ->select('*')
            ->from(CommentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->orderBy('time', 'id')
            ->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /** @return list<Comment> */
    public function findRecentPending(ContentType $contentType, string $ip, int $since): array
    {
        $rows = $this->dbLayer
            ->select('*')
            ->from(CommentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->andWhere('ip = :ip')->setParameter('ip', $ip)
            ->andWhere('shown = 0')
            ->andWhere('sent = 0')
            ->andWhere('time >= :time')->setParameter('time', $since)
            ->orderBy('time DESC', 'id DESC')
            ->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function isValidParent(ContentId $contentId, int $parentId, bool $includeHidden = false): bool
    {
        if ($parentId <= 0) {
            return false;
        }

        $query = $this->dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $parentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->andWhere('deleted = 0')
        ;
        if (!$includeHidden) {
            $query->andWhere('shown = 1');
        }

        return (int)$query->execute()->result() === 1;
    }

    public function count(ContentId $contentId, bool $includeHidden = false): int
    {
        $query = $this->dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
        ;
        if (!$includeHidden) {
            $query->andWhere('shown = 1');
        }

        return (int)$query->execute()->result();
    }

    public function countPending(?ContentType $contentType = null): int
    {
        $query = $this->dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where('shown = 0')
            ->andWhere('sent = 0')
        ;
        if ($contentType instanceof ContentType) {
            $query
                ->andWhere('content_type = :content_type')
                ->setParameter('content_type', $contentType->value)
            ;
        }

        return (int)$query->execute()->result();
    }

    /** @return list<Comment> */
    public function findSubscribers(ContentId $contentId, string $email, bool $sameEmail): array
    {
        $query = $this->dbLayer
            ->select('*')
            ->from(CommentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->andWhere('subscribed = 1')
            ->andWhere('shown = 1')
            ->andWhere("email <> ''")
            ->andWhere($sameEmail ? 'email = :email' : 'email <> :email')->setParameter('email', $email)
            ->orderBy('time', 'id')
            ->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map($this->hydrate(...), $query));
    }

    public function unsubscribe(ContentId $contentId, string $email): void
    {
        $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('subscribed', '0')
            ->where('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->andWhere('subscribed = 1')
            ->andWhere('email = :email')->setParameter('email', $email)
            ->execute()
        ;
    }

    public function publish(
        int                   $commentId,
        ContentType           $contentType,
        CommentMutationSource $source = CommentMutationSource::LOCAL,
    ): void
    {
        $comment = $this->findOfType($commentId, $contentType);
        $updated = $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('shown', '1')
            ->where('id = :id')->setParameter('id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->execute()
            ->affectedRows() > 0
        ;
        if ($updated && $comment instanceof Comment) {
            $this->liveUpdateRepository->publishComments($comment->contentId);
            $this->dispatch($commentId, $comment->contentId, CommentChangeKind::PUBLISHED, $source);
        }
    }

    public function setSent(int $commentId, ContentType $contentType, bool $sent): void
    {
        $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('sent', $sent ? '1' : '0')
            ->where('id = :id')->setParameter('id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->execute()
        ;
    }

    public function markSpam(int $commentId, ContentType $contentType): void
    {
        $comment = $this->findOfType($commentId, $contentType);
        $updated = $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('shown', '0')
            ->set('sent', '1')
            ->where('id = :id')->setParameter('id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->execute()
            ->affectedRows() > 0
        ;
        if ($updated && $comment instanceof Comment) {
            $this->liveUpdateRepository->publishComments($comment->contentId);
            $this->dispatch($commentId, $comment->contentId, CommentChangeKind::HIDDEN);
        }
    }

    public function edit(
        int                   $commentId,
        ContentType           $contentType,
        string                $text,
        CommentMutationSource $source = CommentMutationSource::LOCAL,
    ): bool
    {
        $comment = $this->findOfType($commentId, $contentType);
        $updated = $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('text', ':text')->setParameter('text', $text)
            ->set('modify_time', ':modify_time')->setParameter('modify_time', time())
            ->where('id = :id')->setParameter('id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->andWhere('deleted = 0')
            ->execute()
            ->affectedRows() > 0;
        if ($updated && $comment instanceof Comment) {
            $this->liveUpdateRepository->publishComments($comment->contentId);
            $this->dispatch($commentId, $comment->contentId, CommentChangeKind::EDITED, $source);
        }

        return $updated;
    }

    public function tombstone(
        int                   $commentId,
        ContentType           $contentType,
        CommentMutationSource $source = CommentMutationSource::LOCAL,
    ): bool
    {
        $comment = $this->findOfType($commentId, $contentType);
        $updated = $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('deleted', '1')
            ->set('shown', '0')
            ->set('sent', '1')
            ->set('subscribed', '0')
            ->where('id = :id')->setParameter('id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->execute()
            ->affectedRows() > 0;
        if ($updated && $comment instanceof Comment) {
            $this->liveUpdateRepository->publishComments($comment->contentId);
            $removedIds = $this->pruneLeafTombstones($comment);
            if ($removedIds === []) {
                $this->dispatch($commentId, $comment->contentId, CommentChangeKind::TOMBSTONED, $source);
            } else {
                foreach ($removedIds as $removedId) {
                    $this->dispatch($removedId, $comment->contentId, CommentChangeKind::REMOVED, $source);
                }
            }
        }

        return $updated;
    }

    /**
     * A tombstone is useful only while it anchors replies. Removing the last reply can make an
     * already deleted ancestor unnecessary as well, so prune the whole empty tombstone chain.
     *
     * @return list<int>
     */
    private function pruneLeafTombstones(Comment $comment): array
    {
        $removedIds = [];
        $currentId  = $comment->id;
        $parentId   = $comment->parentId;
        while (!$this->hasReplies($currentId, $comment->contentId)) {
            $removed = $this->dbLayer
                ->delete(CommentSchema::TABLE_NAME)
                ->where('id = :id')->setParameter('id', $currentId)
                ->andWhere('content_type = :content_type')->setParameter('content_type', $comment->contentId->type->value)
                ->andWhere('content_id = :content_id')->setParameter('content_id', $comment->contentId->value)
                ->andWhere('deleted = 1')
                ->execute()
                ->affectedRows() > 0
            ;
            if (!$removed) {
                break;
            }

            $removedIds[] = $currentId;
            if ($parentId === null) {
                break;
            }

            $parent = $this->find($parentId);
            if ($parent === null) {
                break;
            }

            if (!$parent->deleted || !$parent->contentId->equals($comment->contentId)) {
                break;
            }

            $currentId = $parent->id;
            $parentId  = $parent->parentId;
        }

        return $removedIds;
    }

    private function hasReplies(int $commentId, ContentId $contentId): bool
    {
        return (int)$this->dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where('parent_id = :parent_id')->setParameter('parent_id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->execute()
            ->result() > 0;
    }

    public function removeForContent(ContentId $contentId): void
    {
        $comments = $this->findForContent($contentId);
        $removed = $this->dbLayer
            ->delete(CommentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->execute()
            ->affectedRows() > 0
        ;
        if ($removed) {
            $this->liveUpdateRepository->publishComments($contentId);
            foreach ($comments as $comment) {
                $this->dispatch($comment->id, $contentId, CommentChangeKind::REMOVED);
            }
        }
    }

    private function dispatch(
        int                   $commentId,
        ContentId             $contentId,
        CommentChangeKind     $kind,
        CommentMutationSource $source = CommentMutationSource::LOCAL,
    ): void {
        $this->eventDispatcher->dispatch(new CommentChangedEvent($commentId, $contentId, $kind, $source));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Comment
    {
        return new Comment(
            (int)$row['id'],
            new ContentId(ContentType::from((string)$row['content_type']), (int)$row['content_id']),
            $row['parent_id'] === null ? null : (int)$row['parent_id'],
            $row['user_id'] === null ? null : (int)$row['user_id'],
            $row['visitor_id'] === null ? null : (string)$row['visitor_id'],
            $row['userpic_id'] === null ? null : (int)$row['userpic_id'],
            (int)$row['time'],
            (int)$row['modify_time'],
            (string)$row['ip'],
            (string)$row['nick'],
            (string)$row['email'],
            (bool)$row['subscribed'],
            (bool)$row['shown'],
            (bool)$row['deleted'],
            (bool)$row['sent'],
            (bool)$row['good'],
            (string)$row['text'],
        );
    }
}
