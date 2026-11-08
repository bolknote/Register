<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Register\Comment\CommentImportService;
use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Core\Model\UserpicSchema;
use Register\Core\Pdo\DbLayer;
use Register\Import\ExternalImportMapRepository;
use Register\Import\ExternalImportMapSchema;

/** Binds Telegram identities to pictures, filling only comments without an existing picture. */
final readonly class TelegramAvatarService
{
    public function __construct(
        private DbLayer $db,
        private ExternalImportMapRepository $maps,
        private CommentImportService $comments,
        private TelegramAvatarStorage $storage,
    ) {
    }

    public static function validAuthor(string $author): bool
    {
        return preg_match('/^(?:user|channel)([1-9][0-9]{0,15})$/D', $author, $matches) === 1
            && (int)$matches[1] <= 9_007_199_254_740_991;
    }

    /** @param list<string> $authors
     * @return list<string>
     */
    public function requests(int $scope, array $authors = [], int $limit = 10): array
    {
        $retry = $this->maps->forScope('telegram', (string)$scope, 'avatar_request');
        $selected = [];
        foreach ($this->missingComments($scope) as $comment) {
            $author = $comment['author'];
            if (($authors !== [] && !\in_array($author, $authors, true))
                || (int)($retry[$author]['source_data']['retry_at'] ?? 0) > time()
            ) {
                continue;
            }

            $selected[$author] = true;
            if (\count($selected) >= max(1, min(20, $limit))) {
                break;
            }
        }

        return array_keys($selected);
    }

    public function fillCached(int $scope): int
    {
        $avatars = $this->maps->forScope('telegram', (string)$scope, 'avatar');
        $filled = 0;
        foreach ($this->missingComments($scope) as $comment) {
            $avatar = $avatars[$comment['author']] ?? null;
            if (!\is_array($avatar) || $avatar['target_type'] !== 'userpic') {
                continue;
            }

            $picture = $this->db->select('id')->from(UserpicSchema::TABLE_NAME)
                ->where('id = :id')->setParameter('id', $avatar['target_id'])->execute()->fetchAssoc();
            if ($picture !== false && $this->comments->fillMissingUserpic(
                $comment['id'], new ContentId(ContentType::from($comment['type']), $comment['content_id']), $avatar['target_id'],
            )) {
                ++$filled;
            }
        }

        return $filled;
    }

    public function unavailable(int $scope, string $author): void
    {
        $comment = $this->firstMissing($scope, $author);
        if ($comment === null) {
            return;
        }

        $data = ['retry_at' => time() + 86_400];
        $this->maps->store('telegram', (string)$scope, 'avatar_request', $author, 'comment', $comment['id'],
            hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), $data, time());
    }

    public function accept(int $scope, string $author, string $uniqueId, string $bytes): int
    {
        if (!self::validAuthor($author) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $uniqueId) !== 1) {
            throw new \UnexpectedValueException('The Telegram avatar identity is invalid.');
        }

        if ($this->firstMissing($scope, $author) === null) {
            return 0;
        }

        $image = $this->storage->store($bytes);
        $pictureId = $this->pictureId($image);
        $data = ['file_unique_id' => $uniqueId];
        $this->maps->store('telegram', (string)$scope, 'avatar', $author, 'userpic', $pictureId,
            $image['content_hash'], $data, time());

        return $this->fillCached($scope);
    }

    /** @return array{id: int, type: string, content_id: int, author: string}|null */
    private function firstMissing(int $scope, string $author): ?array
    {
        if (!self::validAuthor($author)) {
            throw new \UnexpectedValueException('The Telegram avatar author is invalid.');
        }

        foreach ($this->missingComments($scope) as $comment) {
            if ($comment['author'] === $author) {
                return $comment;
            }
        }

        return null;
    }

    /** @return list<array{id: int, type: string, content_id: int, author: string}> */
    private function missingComments(int $scope): array
    {
        $rows = $this->db->select('c.id, c.content_type, c.content_id, m.source_data')
            ->from(ExternalImportMapSchema::TABLE_NAME . ' AS m')
            ->innerJoin(CommentSchema::TABLE_NAME . ' AS c', 'c.id = m.target_id')
            ->where('m.source = :source')->setParameter('source', 'telegram')
            ->andWhere('m.external_scope = :scope')->setParameter('scope', (string)$scope)
            ->andWhere('m.entity_type = :entity')->setParameter('entity', 'comment')
            ->andWhere('m.target_type = :target')->setParameter('target', 'comment')
            ->andWhere('c.userpic_id IS NULL')->andWhere('c.deleted = 0')
            ->orderBy('c.id')->execute()->fetchAssocAll();
        $result = [];
        foreach ($rows as $row) {
            $data = json_decode((string)$row['source_data'], true, 512, JSON_THROW_ON_ERROR);
            $author = $data['telegram']['comment']['author']['id'] ?? null;
            if (!\is_string($author) || !self::validAuthor($author)) {
                continue;
            }

            $result[] = ['id' => (int)$row['id'], 'type' => (string)$row['content_type'],
                'content_id' => (int)$row['content_id'], 'author' => $author];
        }

        return $result;
    }

    /** @param array{storage_key: string, content_hash: string, mime_type: string, width: int, height: int, byte_size: int} $image */
    private function pictureId(array $image): int
    {
        $find = fn(): array|false => $this->db->select('id')->from(UserpicSchema::TABLE_NAME)
            ->where('content_hash = :hash')->setParameter('hash', $image['content_hash'])->execute()->fetchAssoc();
        $existing = $find();
        if ($existing !== false) {
            return (int)$existing['id'];
        }

        try {
            $insert = $this->db->insert(UserpicSchema::TABLE_NAME);
            foreach ($image as $key => $value) {
                $insert->setValue($key, ':' . $key)->setParameter($key, $value);
            }

            $insert->setValue('source_url', ':source')->setParameter('source', '')
                ->setValue('created_time', ':time')->setParameter('time', time())->execute();
            return (int)$this->db->insertId();
        } catch (\PDOException $exception) {
            $existing = $find();
            if ($existing === false) {
                throw $exception;
            }

            return (int)$existing['id'];
        }
    }
}
