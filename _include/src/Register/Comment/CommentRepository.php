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
use S2\Cms\Pdo\DbLayer;

final readonly class CommentRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function save(
        ContentId $contentId,
        string    $name,
        string    $email,
        bool      $showEmail,
        bool      $subscribed,
        string    $text,
        string    $ip,
        ?int      $parentId,
        ?int      $time = null,
    ): int {
        if ($parentId !== null && !$this->isValidParent($contentId, $parentId)) {
            throw new \InvalidArgumentException('The parent comment does not belong to the commented content.');
        }

        $this->dbLayer
            ->insert(CommentSchema::TABLE_NAME)
            ->values([
                'content_type' => ':content_type',
                'content_id'   => ':content_id',
                'parent_id'    => ':parent_id',
                'time'         => ':time',
                'ip'           => ':ip',
                'nick'         => ':nick',
                'email'        => ':email',
                'show_email'   => ':show_email',
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
                'time'         => $time ?? time(),
                'ip'           => $ip,
                'nick'         => $name,
                'email'        => $email,
                'show_email'   => $showEmail ? 1 : 0,
                'subscribed'   => $subscribed ? 1 : 0,
                'text'         => $text,
            ])
        ;

        return (int)$this->dbLayer->insertId();
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

    public function isValidParent(ContentId $contentId, int $parentId): bool
    {
        if ($parentId <= 0) {
            return false;
        }

        return (int)$this->dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $parentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->andWhere('shown = 1')
            ->andWhere('deleted = 0')
            ->execute()
            ->result() === 1;
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
        if ($contentType !== null) {
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

    public function publish(int $commentId, ContentType $contentType): void
    {
        $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('shown', '1')
            ->where('id = :id')->setParameter('id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->execute()
        ;
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
        $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('shown', '0')
            ->set('sent', '1')
            ->where('id = :id')->setParameter('id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->execute()
        ;
    }

    public function edit(int $commentId, ContentType $contentType, string $text): bool
    {
        return $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('text', ':text')->setParameter('text', $text)
            ->where('id = :id')->setParameter('id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->andWhere('deleted = 0')
            ->execute()
            ->affectedRows() > 0;
    }

    public function tombstone(int $commentId, ContentType $contentType): bool
    {
        return $this->dbLayer
            ->update(CommentSchema::TABLE_NAME)
            ->set('deleted', '1')
            ->set('shown', '0')
            ->set('sent', '1')
            ->set('subscribed', '0')
            ->where('id = :id')->setParameter('id', $commentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->execute()
            ->affectedRows() > 0;
    }

    public function removeForContent(ContentId $contentId): void
    {
        $this->dbLayer
            ->delete(CommentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->execute()
        ;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Comment
    {
        return new Comment(
            (int)$row['id'],
            new ContentId(ContentType::from((string)$row['content_type']), (int)$row['content_id']),
            $row['parent_id'] === null ? null : (int)$row['parent_id'],
            $row['userpic_id'] === null ? null : (int)$row['userpic_id'],
            (int)$row['time'],
            (string)$row['ip'],
            (string)$row['nick'],
            (string)$row['email'],
            (bool)$row['show_email'],
            (bool)$row['subscribed'],
            (bool)$row['shown'],
            (bool)$row['deleted'],
            (bool)$row['sent'],
            (bool)$row['good'],
            (string)$row['text'],
        );
    }
}
