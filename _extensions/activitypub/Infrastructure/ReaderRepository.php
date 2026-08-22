<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use S2\Cms\Pdo\DbLayer;
use s2_extensions\activitypub\Domain\CollectionAnchor;
use s2_extensions\activitypub\Domain\ReaderEntry;

/** Private chronological reader over normalized, sanitized remote object snapshots. */
final readonly class ReaderRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function count(int $localActorId, string $view): int
    {
        $query = $this->baseQuery('COUNT(*)')
            ->where('recipient.local_actor_id = :local_actor_id')->setParameter('local_actor_id', $localActorId)
            ->andWhere('remote_object.state = :state')->setParameter('state', 'live')
        ;
        $this->viewCondition($query, $view);

        return (int)$query->execute()->result();
    }

    /** @return list<ReaderEntry> */
    public function page(
        int               $localActorId,
        string            $view,
        ?CollectionAnchor $before,
        int               $limit,
    ): array {
        if ($localActorId < 1 || $limit < 1 || $limit > 101) {
            throw new \InvalidArgumentException('The ActivityPub reader page is invalid.');
        }

        $query = $this->baseQuery(
            'remote_object.id AS object_id',
            'remote_object.object_url',
            'remote_object.object_type',
            'remote_object.in_reply_to_url',
            'remote_object.visibility',
            'COALESCE(remote_object.published_at, remote_object.fetched_at) AS sort_at',
            'remote_actor.id AS remote_actor_id',
            'remote_actor.actor_url',
            'remote_actor.preferred_username',
            'remote_actor.display_name',
            'recipient.recipient_kind',
            'snapshot.document_json',
        )
            ->where('recipient.local_actor_id = :local_actor_id')->setParameter('local_actor_id', $localActorId)
            ->andWhere('remote_object.state = :state')->setParameter('state', 'live')
            ->orderBy('sort_at DESC, remote_object.id DESC')
            ->limit($limit)
        ;
        $this->viewCondition($query, $view);
        if ($before instanceof CollectionAnchor) {
            $query->andWhere('(
                COALESCE(remote_object.published_at, remote_object.fetched_at) < :before_time
                OR (
                    COALESCE(remote_object.published_at, remote_object.fetched_at) = :before_time
                    AND remote_object.id < :before_id
                )
            )')
                ->setParameter('before_time', $before->timestamp)
                ->setParameter('before_id', $before->id)
            ;
        }

        $rows = $query->execute()->fetchAssocAll();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    private function baseQuery(string ...$fields): \S2\Cms\Pdo\QueryBuilder\SelectBuilder
    {
        return $this->dbLayer->select(...$fields)
            ->from(ActivityPubSchema::REMOTE_RECIPIENT_TABLE . ' AS recipient')
            ->innerJoin(
                ActivityPubSchema::REMOTE_OBJECT_TABLE . ' AS remote_object',
                'remote_object.id = recipient.remote_object_id',
            )
            ->innerJoin(
                ActivityPubSchema::REMOTE_ACTOR_TABLE . ' AS remote_actor',
                'remote_actor.id = remote_object.owner_actor_id',
            )
            ->innerJoin(
                ActivityPubSchema::REMOTE_SNAPSHOT_TABLE . ' AS snapshot',
                'snapshot.id = remote_object.current_snapshot_id',
            )
        ;
    }

    private function viewCondition(\S2\Cms\Pdo\QueryBuilder\SelectBuilder $query, string $view): void
    {
        match ($view) {
            'feed' => $query->andWhere('remote_object.visibility IN (:public, :unlisted, :followers)')
                ->setParameter('public', 'public')
                ->setParameter('unlisted', 'unlisted')
                ->setParameter('followers', 'followers'),
            'direct' => $query->andWhere('remote_object.visibility = :direct')
                ->setParameter('direct', 'direct'),
            'all' => null,
            default => throw new \InvalidArgumentException('The ActivityPub reader view is invalid.'),
        };
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ReaderEntry
    {
        try {
            $document = json_decode((string)$row['document_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!\is_array($document) || array_is_list($document)) {
                throw new \JsonException('Expected an ActivityPub reader object document.');
            }

            return new ReaderEntry(
                (int)$row['object_id'],
                (string)$row['object_url'],
                (string)$row['object_type'],
                $row['in_reply_to_url'] === null ? null : (string)$row['in_reply_to_url'],
                (string)$row['visibility'],
                (int)$row['sort_at'],
                (int)$row['remote_actor_id'],
                (string)$row['actor_url'],
                (string)$row['preferred_username'],
                (string)$row['display_name'],
                (string)$row['recipient_kind'],
                $document,
            );
        } catch (\JsonException | \InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored ActivityPub reader entry is invalid.', 0, $exception);
        }
    }
}
