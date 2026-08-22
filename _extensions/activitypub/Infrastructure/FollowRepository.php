<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\FollowRelationship;

final readonly class FollowRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function recordIncoming(
        int    $localActorId,
        int    $remoteActorId,
        string $followActivityUrl,
        bool   $accepted,
        int    $now,
    ): int {
        $this->validate($localActorId, $remoteActorId, $followActivityUrl, $now);
        $existing = $this->relationship('incoming', $localActorId, $remoteActorId);
        if ($existing === null) {
            $this->dbLayer->insert(ActivityPubSchema::FOLLOW_TABLE)
                ->values([
                    'direction'           => ':direction',
                    'local_actor_id'       => ':local_actor_id',
                    'remote_actor_id'      => ':remote_actor_id',
                    'state'                => ':state',
                    'follow_activity_url'  => ':follow_activity_url',
                    'follow_activity_hash' => ':follow_activity_hash',
                    'created_at'           => ':created_at',
                    'updated_at'           => ':updated_at',
                    'accepted_at'          => ':accepted_at',
                ])
                ->execute([
                    'direction'            => 'incoming',
                    'local_actor_id'        => $localActorId,
                    'remote_actor_id'       => $remoteActorId,
                    'state'                 => $accepted ? 'accepted' : 'pending',
                    'follow_activity_url'   => $followActivityUrl,
                    'follow_activity_hash'  => hash('sha256', $followActivityUrl),
                    'created_at'            => $now,
                    'updated_at'            => $now,
                    'accepted_at'           => $accepted ? $now : null,
                ])
            ;
            $id = (int)$this->dbLayer->insertId();
            if ($id < 1) {
                throw new \RuntimeException('Unable to obtain the ActivityPub follow identifier.');
            }

            return $id;
        }

        $this->dbLayer->update(ActivityPubSchema::FOLLOW_TABLE)
            ->set('state', ':state')->setParameter('state', $accepted ? 'accepted' : 'pending')
            ->set('follow_activity_url', ':follow_activity_url')->setParameter('follow_activity_url', $followActivityUrl)
            ->set('follow_activity_hash', ':follow_activity_hash')->setParameter('follow_activity_hash', hash('sha256', $followActivityUrl))
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->set('accepted_at', ':accepted_at')->setParameter('accepted_at', $accepted ? $now : null)
            ->set('ended_at', 'NULL')
            ->where('id = :id')->setParameter('id', (int)$existing['id'])
            ->execute()
        ;

        return (int)$existing['id'];
    }

    public function findOutgoing(int $localActorId, int $remoteActorId): ?FollowRelationship
    {
        if ($localActorId < 1 || $remoteActorId < 1) {
            return null;
        }

        $row = $this->relationship('outgoing', $localActorId, $remoteActorId);

        return $row === null ? null : $this->hydrate($row);
    }

    public function recordOutgoing(
        int    $localActorId,
        int    $remoteActorId,
        string $followActivityUrl,
        int    $localActivityId,
        int    $now,
    ): FollowRelationship {
        $this->validate($localActorId, $remoteActorId, $followActivityUrl, $now);
        if ($localActivityId < 1) {
            throw new \InvalidArgumentException('An outgoing ActivityPub Follow requires a stored local activity.');
        }

        $existing = $this->relationship('outgoing', $localActorId, $remoteActorId);
        if ($existing !== null && \in_array((string)$existing['state'], ['pending', 'accepted'], true)) {
            throw new \DomainException('This local actor already follows or is awaiting the remote actor.');
        }

        if ($existing === null) {
            $this->dbLayer->insert(ActivityPubSchema::FOLLOW_TABLE)
                ->values([
                    'direction'           => ':direction',
                    'local_actor_id'       => ':local_actor_id',
                    'remote_actor_id'      => ':remote_actor_id',
                    'state'                => ':state',
                    'follow_activity_url'  => ':follow_activity_url',
                    'follow_activity_hash' => ':follow_activity_hash',
                    'local_activity_id'    => ':local_activity_id',
                    'created_at'           => ':created_at',
                    'updated_at'           => ':updated_at',
                ])
                ->execute([
                    'direction'            => 'outgoing',
                    'local_actor_id'        => $localActorId,
                    'remote_actor_id'       => $remoteActorId,
                    'state'                 => 'pending',
                    'follow_activity_url'   => $followActivityUrl,
                    'follow_activity_hash'  => hash('sha256', $followActivityUrl),
                    'local_activity_id'     => $localActivityId,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ])
            ;
        } else {
            $this->dbLayer->update(ActivityPubSchema::FOLLOW_TABLE)
                ->set('state', ':state')->setParameter('state', 'pending')
                ->set('follow_activity_url', ':follow_activity_url')->setParameter('follow_activity_url', $followActivityUrl)
                ->set('follow_activity_hash', ':follow_activity_hash')->setParameter('follow_activity_hash', hash('sha256', $followActivityUrl))
                ->set('local_activity_id', ':local_activity_id')->setParameter('local_activity_id', $localActivityId)
                ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
                ->set('accepted_at', 'NULL')
                ->set('ended_at', 'NULL')
                ->where('id = :id')->setParameter('id', (int)$existing['id'])
                ->execute()
            ;
        }

        return $this->findOutgoing($localActorId, $remoteActorId)
            ?? throw new \RuntimeException('The outgoing ActivityPub follow relationship cannot be reloaded.');
    }

    public function endOutgoing(int $localActorId, int $remoteActorId, int $now): bool
    {
        if ($localActorId < 1 || $remoteActorId < 1 || $now < 1) {
            throw new \InvalidArgumentException('An outgoing ActivityPub unfollow transition is invalid.');
        }

        return $this->dbLayer->update(ActivityPubSchema::FOLLOW_TABLE)
            ->set('state', ':state')->setParameter('state', 'ended')
            ->set('ended_at', ':ended_at')->setParameter('ended_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('direction = :direction')->setParameter('direction', 'outgoing')
            ->andWhere('local_actor_id = :local_actor_id')->setParameter('local_actor_id', $localActorId)
            ->andWhere('remote_actor_id = :remote_actor_id')->setParameter('remote_actor_id', $remoteActorId)
            ->andWhere('state IN (:pending, :accepted)')
            ->setParameter('pending', 'pending')
            ->setParameter('accepted', 'accepted')
            ->execute()
            ->affectedRows() === 1
        ;
    }

    public function endIncomingByActivity(
        int    $remoteActorId,
        string $followActivityUrl,
        ?int   $targetLocalActorId,
        int    $now,
    ): bool {
        $query = $this->dbLayer->update(ActivityPubSchema::FOLLOW_TABLE)
            ->set('state', ':state')->setParameter('state', 'ended')
            ->set('ended_at', ':ended_at')->setParameter('ended_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('direction = :direction')->setParameter('direction', 'incoming')
            ->andWhere('remote_actor_id = :remote_actor_id')->setParameter('remote_actor_id', $remoteActorId)
            ->andWhere('follow_activity_hash = :follow_activity_hash')
            ->setParameter('follow_activity_hash', hash('sha256', $followActivityUrl))
            ->andWhere('follow_activity_url = :follow_activity_url')->setParameter('follow_activity_url', $followActivityUrl)
            ->andWhere('state IN (:pending, :accepted)')
            ->setParameter('pending', 'pending')
            ->setParameter('accepted', 'accepted')
        ;
        if ($targetLocalActorId !== null) {
            $query->andWhere('local_actor_id = :local_actor_id')->setParameter('local_actor_id', $targetLocalActorId);
        }

        return $query->execute()->affectedRows() > 0;
    }

    public function hasIncomingByActivity(int $remoteActorId, string $followActivityUrl, ?int $targetLocalActorId): bool
    {
        $query = $this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::FOLLOW_TABLE)
            ->where('direction = :direction')->setParameter('direction', 'incoming')
            ->andWhere('remote_actor_id = :remote_actor_id')->setParameter('remote_actor_id', $remoteActorId)
            ->andWhere('follow_activity_hash = :follow_activity_hash')
            ->setParameter('follow_activity_hash', hash('sha256', $followActivityUrl))
            ->andWhere('follow_activity_url = :follow_activity_url')->setParameter('follow_activity_url', $followActivityUrl)
        ;
        if ($targetLocalActorId !== null) {
            $query->andWhere('local_actor_id = :local_actor_id')->setParameter('local_actor_id', $targetLocalActorId);
        }

        return (int)$query->execute()->result() > 0;
    }

    public function recordOutgoingResponse(int $remoteActorId, int $localActivityId, bool $accepted, int $now): bool
    {
        return $this->dbLayer->update(ActivityPubSchema::FOLLOW_TABLE)
            ->set('state', ':state')->setParameter('state', $accepted ? 'accepted' : 'rejected')
            ->set('accepted_at', ':accepted_at')->setParameter('accepted_at', $accepted ? $now : null)
            ->set('ended_at', ':ended_at')->setParameter('ended_at', $accepted ? null : $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('direction = :direction')->setParameter('direction', 'outgoing')
            ->andWhere('remote_actor_id = :remote_actor_id')->setParameter('remote_actor_id', $remoteActorId)
            ->andWhere('local_activity_id = :local_activity_id')->setParameter('local_activity_id', $localActivityId)
            ->andWhere('state = :pending')->setParameter('pending', 'pending')
            ->execute()
            ->affectedRows() === 1
        ;
    }

    public function endAllWithRemote(int $remoteActorId, int $now): int
    {
        return $this->dbLayer->update(ActivityPubSchema::FOLLOW_TABLE)
            ->set('state', ':state')->setParameter('state', 'ended')
            ->set('ended_at', ':ended_at')->setParameter('ended_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('remote_actor_id = :remote_actor_id')->setParameter('remote_actor_id', $remoteActorId)
            ->andWhere('state IN (:pending, :accepted)')
            ->setParameter('pending', 'pending')
            ->setParameter('accepted', 'accepted')
            ->execute()
            ->affectedRows()
        ;
    }

    /** Transfers signed incoming relationships after a verified actor Move. */
    public function migrateIncomingRemoteActor(int $oldRemoteActorId, int $newRemoteActorId, int $now): int
    {
        if ($oldRemoteActorId < 1 || $newRemoteActorId < 1 || $oldRemoteActorId === $newRemoteActorId || $now < 1) {
            throw new \InvalidArgumentException('An incoming ActivityPub follow migration is invalid.');
        }

        $rows = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::FOLLOW_TABLE)
            ->where('direction = :direction')->setParameter('direction', 'incoming')
            ->andWhere('remote_actor_id = :remote_actor_id')->setParameter('remote_actor_id', $oldRemoteActorId)
            ->andWhere('state IN (:pending, :accepted)')
            ->setParameter('pending', 'pending')
            ->setParameter('accepted', 'accepted')
            ->orderBy('id')
            ->execute()
            ->fetchAssocAll()
        ;
        $migrated = 0;
        foreach ($rows as $row) {
            $localActorId = (int)$row['local_actor_id'];
            $target = $this->relationship('incoming', $localActorId, $newRemoteActorId);
            if ($target === null) {
                $affected = $this->dbLayer->update(ActivityPubSchema::FOLLOW_TABLE)
                    ->set('remote_actor_id', ':new_remote_actor_id')
                    ->setParameter('new_remote_actor_id', $newRemoteActorId)
                    ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
                    ->where('id = :id')->setParameter('id', (int)$row['id'])
                    ->andWhere('remote_actor_id = :old_remote_actor_id')
                    ->setParameter('old_remote_actor_id', $oldRemoteActorId)
                    ->andWhere('state IN (:pending, :accepted)')
                    ->setParameter('pending', 'pending')
                    ->setParameter('accepted', 'accepted')
                    ->execute()
                    ->affectedRows()
                ;
                if ($affected !== 1) {
                    throw new \RuntimeException('An incoming ActivityPub follow changed concurrently during Move.');
                }
            } else {
                $accepted = $row['state'] === 'accepted' || $target['state'] === 'accepted';
                $this->dbLayer->update(ActivityPubSchema::FOLLOW_TABLE)
                    ->set('state', ':state')->setParameter('state', $accepted ? 'accepted' : 'pending')
                    ->set('follow_activity_url', ':follow_activity_url')
                    ->setParameter('follow_activity_url', (string)$row['follow_activity_url'])
                    ->set('follow_activity_hash', ':follow_activity_hash')
                    ->setParameter('follow_activity_hash', (string)$row['follow_activity_hash'])
                    ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
                    ->set('accepted_at', ':accepted_at')->setParameter('accepted_at', $accepted ? $now : null)
                    ->set('ended_at', 'NULL')
                    ->where('id = :id')->setParameter('id', (int)$target['id'])
                    ->execute()
                ;
                if (!$this->endRelationshipById((int)$row['id'], $now)) {
                    throw new \RuntimeException('An incoming ActivityPub follow changed concurrently during Move merge.');
                }
            }

            ++$migrated;
        }

        return $migrated;
    }

    /** @return list<int> */
    public function acceptedOutgoingLocalActorIds(int $remoteActorId): array
    {
        if ($remoteActorId < 1) {
            return [];
        }

        $ids = $this->dbLayer->select('local_actor_id')
            ->from(ActivityPubSchema::FOLLOW_TABLE)
            ->where('direction = :direction')->setParameter('direction', 'outgoing')
            ->andWhere('remote_actor_id = :remote_actor_id')->setParameter('remote_actor_id', $remoteActorId)
            ->andWhere('state = :state')->setParameter('state', 'accepted')
            ->orderBy('local_actor_id')
            ->execute()
            ->fetchColumn()
        ;

        return array_values(array_map(intval(...), $ids));
    }

    /** @return array<string, mixed>|null */
    private function relationship(string $direction, int $localActorId, int $remoteActorId): ?array
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::FOLLOW_TABLE)
            ->where('direction = :direction')->setParameter('direction', $direction)
            ->andWhere('local_actor_id = :local_actor_id')->setParameter('local_actor_id', $localActorId)
            ->andWhere('remote_actor_id = :remote_actor_id')->setParameter('remote_actor_id', $remoteActorId)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $row : null;
    }

    private function validate(int $localActorId, int $remoteActorId, string $activityUrl, int $now): void
    {
        if ($localActorId < 1
            || $remoteActorId < 1
            || !str_starts_with($activityUrl, 'https://')
            || \strlen($activityUrl) > 2_048
            || $now < 1
        ) {
            throw new \InvalidArgumentException('The ActivityPub follow relationship input is invalid.');
        }
    }

    private function endRelationshipById(int $id, int $now): bool
    {
        return $this->dbLayer->update(ActivityPubSchema::FOLLOW_TABLE)
            ->set('state', ':state')->setParameter('state', 'ended')
            ->set('ended_at', ':ended_at')->setParameter('ended_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $id)
            ->andWhere('state IN (:pending, :accepted)')
            ->setParameter('pending', 'pending')
            ->setParameter('accepted', 'accepted')
            ->execute()
            ->affectedRows() === 1
        ;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): FollowRelationship
    {
        try {
            return new FollowRelationship(
                (int)$row['id'],
                (string)$row['direction'],
                (int)$row['local_actor_id'],
                (int)$row['remote_actor_id'],
                (string)$row['state'],
                (string)$row['follow_activity_url'],
                $row['local_activity_id'] === null ? null : (int)$row['local_activity_id'],
                (int)$row['created_at'],
                (int)$row['updated_at'],
                $row['accepted_at'] === null ? null : (int)$row['accepted_at'],
                $row['ended_at'] === null ? null : (int)$row['ended_at'],
            );
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored ActivityPub follow relationship is invalid.', 0, $exception);
        }
    }
}
