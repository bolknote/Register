<?php

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use Register\Content\PostCreateOperationSchema;
use Register\Core\Pdo\DbLayer;

/** The caller holds the editorial mutex and commits the receipt with the post. */
final readonly class PostCreateOperations
{
    public function __construct(private DbLayer $db)
    {
    }

    /** @return array{post_id: int, request_hash: string}|null */
    public function find(int $userId, string $requestId): ?array
    {
        $row = $this->db->select('post_id, request_hash')->from(PostCreateOperationSchema::TABLE_NAME)
            ->where('operation_key = :key')->setParameter('key', $this->key($userId, $requestId))
            ->execute()->fetchAssoc();

        // SET NULL marks a deleted post; zero cannot identify a replacement row.
        return $row === false ? null : ['post_id' => (int)$row['post_id'], 'request_hash' => (string)$row['request_hash']];
    }

    public function remember(int $userId, string $requestId, string $hash, int $postId): void
    {
        $this->db->insert(PostCreateOperationSchema::TABLE_NAME)->values([
            'operation_key' => ':key', 'request_hash' => ':hash', 'post_id' => ':id', 'created_at' => ':time',
        ])->execute(['key' => $this->key($userId, $requestId), 'hash' => $hash, 'id' => $postId, 'time' => time()]);
    }

    private function key(int $userId, string $requestId): string
    {
        return hash('sha256', $userId . "\0" . $requestId);
    }
}
