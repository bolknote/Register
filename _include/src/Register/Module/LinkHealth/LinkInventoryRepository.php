<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;

final readonly class LinkInventoryRepository
{
    public function __construct(
        private DbLayer $dbLayer,
        private \PDO    $pdo,
        private string  $dbPrefix,
    ) {
    }

    public function publishedRevision(ContentId $contentId): ?int
    {
        $revision = $this->dbLayer
            ->select('revision')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $contentId->value)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('published = 1')
            ->execute()
            ->result()
        ;

        if (\is_int($revision)) {
            return $revision;
        }

        if (\is_string($revision) && ctype_digit($revision)) {
            return (int)$revision;
        }

        return null;
    }

    /**
     * @param list<DiscoveredLink> $links
     * @return list<LinkTarget>
     */
    public function synchronize(ContentId $sourceContentId, int $revision, array $links, int $now): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $existingFirstSeen = $this->existingFirstSeen($sourceContentId);
            $targets           = [];
            foreach ($links as $link) {
                $target = $this->findOrCreateTarget($link, $now);
                $targets[$target->id] = $target;

                $this->dbLayer->upsert(Manifest::CONTENT_LINK_TABLE)
                    ->setKey('source_content_id', ':source_content_id')->setParameter('source_content_id', $sourceContentId->value)
                    ->setKey('target_id', ':target_id')->setParameter('target_id', $target->id)
                    ->setValue('original_href', ':original_href')->setParameter('original_href', $link->originalHref)
                    ->setValue('occurrence_count', ':occurrence_count')->setParameter('occurrence_count', $link->occurrenceCount)
                    ->setValue('content_revision', ':content_revision')->setParameter('content_revision', $revision)
                    ->setValue('first_seen_at', ':first_seen_at')->setParameter('first_seen_at', $existingFirstSeen[$target->id] ?? $now)
                    ->setValue('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
                    ->execute()
                ;
            }

            $this->deleteStaleContentLinks($sourceContentId, array_keys($targets));
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return array_values($targets);
        } catch (\Throwable $throwable) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }

    public function removeContent(ContentId $contentId): void
    {
        $this->dbLayer->delete(Manifest::CONTENT_LINK_TABLE)
            ->where('source_content_id = :source_content_id')->setParameter('source_content_id', $contentId->value)
            ->execute()
        ;
    }

    /** @return list<int> */
    public function dueTargetIds(int $now, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The due-target limit must be positive.');
        }

        $rows = $this->dbLayer
            ->select('id')
            ->from(Manifest::TARGET_TABLE)
            ->where('kind = :kind')->setParameter('kind', LinkKind::EXTERNAL->value)
            ->andWhere('health_status NOT IN (:broken, :ignored, :blocked)')
            ->setParameter('broken', LinkHealthStatus::BROKEN->value)
            ->setParameter('ignored', LinkHealthStatus::IGNORED->value)
            ->setParameter('blocked', LinkHealthStatus::BLOCKED->value)
            ->andWhere('id IN (SELECT target_id FROM ' . $this->dbPrefix . Manifest::CONTENT_LINK_TABLE . ')')
            ->andWhere('next_check_at IS NOT NULL')
            ->andWhere('next_check_at <= :now')->setParameter('now', $now)
            ->orderBy('next_check_at, id')
            ->limit($limit)
            ->execute()
            ->fetchColumn()
        ;

        return array_values(array_map(static fn(mixed $id): int => (int)$id, $rows));
    }

    /** @return list<int> */
    public function repairableTargetIds(int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The repairable-target limit must be positive.');
        }

        $rows = $this->dbLayer
            ->select('id')
            ->from(Manifest::TARGET_TABLE)
            ->where('kind = :kind')->setParameter('kind', LinkKind::EXTERNAL->value)
            ->andWhere('health_status = :health_status')
            ->setParameter('health_status', LinkHealthStatus::BROKEN->value)
            ->andWhere('archive_status = :archive_status')
            ->setParameter('archive_status', ArchiveStatus::AVAILABLE->value)
            ->andWhere('archive_url IS NOT NULL')
            ->andWhere('id IN (SELECT target_id FROM ' . $this->dbPrefix . Manifest::CONTENT_LINK_TABLE . ')')
            ->orderBy('last_seen_at, id')
            ->limit($limit)
            ->execute()
            ->fetchColumn()
        ;

        return array_values(array_map(static fn(mixed $id): int => (int)$id, $rows));
    }

    private function findOrCreateTarget(DiscoveredLink $link, int $now): LinkTarget
    {
        $hash          = hash('sha256', $link->link->url);
        $initialHealth = $link->link->kind === LinkKind::EXTERNAL
            ? LinkHealthStatus::UNKNOWN
            : LinkHealthStatus::SKIPPED;
        $archiveStatus = $link->link->kind === LinkKind::EXTERNAL
            ? ArchiveStatus::UNCHECKED
            : ArchiveStatus::NOT_APPLICABLE;
        $nextCheckAt   = $link->link->kind === LinkKind::EXTERNAL ? $now : null;
        $table         = $this->dbPrefix . Manifest::TARGET_TABLE;
        $driver        = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!\is_string($driver)) {
            throw new \RuntimeException('PDO returned an invalid driver name.');
        }

        $sql = match ($driver) {
            'mysql' => 'INSERT INTO ' . $table . ' (url_hash, normalized_url, kind, host, local_content_id, health_status, '
                . 'failure_count, first_seen_at, last_seen_at, next_check_at, archive_status) '
                . 'VALUES (:url_hash, :normalized_url, :kind, :host, :local_content_id, :health_status, 0, :first_seen_at, '
                . ':last_seen_at, :next_check_at, :archive_status) ON DUPLICATE KEY UPDATE url_hash = VALUES(url_hash)',
            'sqlite', 'pgsql' => 'INSERT INTO ' . $table . ' (url_hash, normalized_url, kind, host, local_content_id, health_status, '
                . 'failure_count, first_seen_at, last_seen_at, next_check_at, archive_status) '
                . 'VALUES (:url_hash, :normalized_url, :kind, :host, :local_content_id, :health_status, 0, :first_seen_at, '
                . ':last_seen_at, :next_check_at, :archive_status) ON CONFLICT (url_hash) DO NOTHING',
            default => throw new \RuntimeException(\sprintf('Unsupported PDO driver "%s".', $driver)),
        };
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to prepare link-target insertion.');
        }

        $statement->execute([
            'url_hash'        => $hash,
            'normalized_url'  => $link->link->url,
            'kind'            => $link->link->kind->value,
            'host'            => $link->link->host,
            'local_content_id' => $link->localContentId?->value,
            'health_status'   => $initialHealth->value,
            'first_seen_at'   => $now,
            'last_seen_at'    => $now,
            'next_check_at'   => $nextCheckAt,
            'archive_status'  => $archiveStatus->value,
        ]);

        $row = $this->dbLayer
            ->select('id, normalized_url, kind, health_status, next_check_at')
            ->from(Manifest::TARGET_TABLE)
            ->where('url_hash = :url_hash')->setParameter('url_hash', $hash)
            ->execute()
            ->fetchAssoc()
        ;
        if ($row === false) {
            throw new \RuntimeException('The inserted link target cannot be found.');
        }

        if ((string)$row['normalized_url'] !== $link->link->url) {
            throw new \RuntimeException('A SHA-256 collision was detected in the link inventory.');
        }

        $targetId = (int)$row['id'];
        $this->dbLayer->update(Manifest::TARGET_TABLE)
            ->set('kind', ':kind')->setParameter('kind', $link->link->kind->value)
            ->set('host', ':host')->setParameter('host', $link->link->host)
            ->set('local_content_id', ':local_content_id')->setParameter('local_content_id', $link->localContentId?->value)
            ->set('last_seen_at', ':last_seen_at')->setParameter('last_seen_at', $now)
            ->where('id = :id')->setParameter('id', $targetId)
            ->execute()
        ;

        $next = $row['next_check_at'];

        return new LinkTarget(
            $targetId,
            (string)$row['normalized_url'],
            LinkKind::from((string)$row['kind']),
            LinkHealthStatus::from((string)$row['health_status']),
            $next === null ? null : (int)$next,
        );
    }

    /** @return array<int, int> */
    private function existingFirstSeen(ContentId $contentId): array
    {
        $rows = $this->dbLayer
            ->select('target_id', 'first_seen_at')
            ->from(Manifest::CONTENT_LINK_TABLE)
            ->where('source_content_id = :source_content_id')->setParameter('source_content_id', $contentId->value)
            ->execute()
            ->fetchAssocAll()
        ;

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['target_id']] = (int)$row['first_seen_at'];
        }

        return $result;
    }

    /** @param list<int> $targetIds */
    private function deleteStaleContentLinks(ContentId $contentId, array $targetIds): void
    {
        $query = $this->dbLayer->delete(Manifest::CONTENT_LINK_TABLE)
            ->where('source_content_id = :source_content_id')->setParameter('source_content_id', $contentId->value)
        ;
        if ($targetIds !== []) {
            $parameters   = [];
            $placeholders = [];
            foreach ($targetIds as $index => $targetId) {
                $name                = 'target_' . $index;
                $parameters[$name]   = $targetId;
                $placeholders[]      = ':' . $name;
            }

            $query->andWhere('target_id NOT IN (' . implode(', ', $placeholders) . ')');
            foreach ($parameters as $name => $value) {
                $query->setParameter($name, $value);
            }
        }

        $query->execute();
    }
}
