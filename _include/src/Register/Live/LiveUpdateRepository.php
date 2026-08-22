<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Live;

use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;

final readonly class LiveUpdateRepository
{
    public const string TOPIC_CONTENT = 'content';

    public const string TOPIC_COMMENTS = 'comments';

    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function publishContent(ContentId $contentId): int
    {
        return $this->publish(self::TOPIC_CONTENT, $contentId);
    }

    public function publishComments(ContentId $contentId): int
    {
        return $this->publish(self::TOPIC_COMMENTS, $contentId);
    }

    public function currentCursor(): int
    {
        return (int)$this->dbLayer
            ->select('MAX(id)')
            ->from(LiveUpdateSchema::TABLE_NAME)
            ->execute()
            ->result()
        ;
    }

    /**
     * @return list<LiveUpdate>
     */
    public function findAfter(int $cursor, int $limit): array
    {
        if ($cursor < 0) {
            throw new \InvalidArgumentException('A live-update cursor cannot be negative.');
        }

        if ($limit < 1) {
            throw new \InvalidArgumentException('A live-update limit must be positive.');
        }

        $rows = $this->dbLayer
            ->select('id, topic, content_type, content_id')
            ->from(LiveUpdateSchema::TABLE_NAME)
            ->where('id > :cursor')->setParameter('cursor', $cursor)
            ->orderBy('id')
            ->limit($limit)
            ->execute()
            ->fetchAssocAll()
        ;

        $updates = [];
        foreach ($rows as $row) {
            $contentType = ContentType::tryFrom((string)$row['content_type']);
            $topic       = (string)$row['topic'];
            $contentId   = (int)$row['content_id'];
            $rowCursor   = (int)$row['id'];
            if (
                $contentType === null
                || !\in_array($topic, [self::TOPIC_CONTENT, self::TOPIC_COMMENTS], true)
                || $contentId <= 0
                || $rowCursor <= 0
            ) {
                continue;
            }

            $updates[] = new LiveUpdate(
                $rowCursor,
                $topic,
                new ContentId($contentType, $contentId),
            );
        }

        return $updates;
    }

    private function publish(string $topic, ContentId $contentId): int
    {
        $this->dbLayer
            ->insert(LiveUpdateSchema::TABLE_NAME)
            ->values([
                'topic'        => ':topic',
                'content_type' => ':content_type',
                'content_id'   => ':content_id',
                'created_at'   => ':created_at',
            ])
            ->execute([
                'topic'        => $topic,
                'content_type' => $contentId->type->value,
                'content_id'   => $contentId->value,
                'created_at'   => time(),
            ])
        ;

        return (int)$this->dbLayer->insertId();
    }
}
