<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Domain\CollectionAnchor;

final readonly class LocalFederationRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function findObject(string $publicId): ?StoredObjectRepresentation
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::OBJECT_TABLE)
            ->where('public_id = :public_id')->setParameter('public_id', $publicId)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateObject($row) : null;
    }

    public function findLiveObject(ContentId $contentId): ?StoredObjectRepresentation
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::OBJECT_TABLE)
            ->where('local_type = :local_type')->setParameter('local_type', $contentId->type->value)
            ->andWhere('local_id = :local_id')->setParameter('local_id', $contentId->value)
            ->andWhere('state = :state')->setParameter('state', 'live')
            ->orderBy('incarnation DESC')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateObject($row) : null;
    }

    public function findObjectById(int $id): ?StoredObjectRepresentation
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::OBJECT_TABLE)
            ->where('id = :id')->setParameter('id', $id)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateObject($row) : null;
    }

    public function findLiveObjectByCanonicalUrl(string $canonicalUrl): ?StoredObjectRepresentation
    {
        if (!str_starts_with($canonicalUrl, 'https://') || \strlen($canonicalUrl) > 2_048) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::OBJECT_TABLE)
            ->where('canonical_url_hash = :hash')->setParameter('hash', hash('sha256', $canonicalUrl))
            ->andWhere('canonical_url = :url')->setParameter('url', $canonicalUrl)
            ->andWhere('state = :state')->setParameter('state', 'live')
            ->orderBy('incarnation DESC')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateObject($row) : null;
    }

    public function findLocalNote(string $publicId): ?StoredLocalNoteRepresentation
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::LOCAL_NOTE_TABLE)
            ->where('public_id = :public_id')->setParameter('public_id', $publicId)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateLocalNote($row) : null;
    }

    public function findLocalNoteById(int $id): ?StoredLocalNoteRepresentation
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::LOCAL_NOTE_TABLE)
            ->where('id = :id')->setParameter('id', $id)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateLocalNote($row) : null;
    }

    public function insertLocalNote(NewStoredLocalNote $note): StoredLocalNoteRepresentation
    {
        $this->dbLayer->insert(ActivityPubSchema::LOCAL_NOTE_TABLE)
            ->values([
                'public_id'         => ':public_id',
                'actor_id'          => ':actor_id',
                'in_reply_to_hash'  => ':in_reply_to_hash',
                'in_reply_to_url'   => ':in_reply_to_url',
                'remote_actor_id'   => ':remote_actor_id',
                'visibility'        => ':visibility',
                'state'             => ':state',
                'snapshot_json'     => ':snapshot_json',
                'snapshot_hash'     => ':snapshot_hash',
                'published_at'      => ':published_at',
                'updated_at'        => ':updated_at',
                'created_at'        => ':created_at',
            ])
            ->execute([
                'public_id'          => $note->publicId,
                'actor_id'           => $note->actorId,
                'in_reply_to_hash'   => hash('sha256', $note->inReplyToUrl),
                'in_reply_to_url'    => $note->inReplyToUrl,
                'remote_actor_id'    => $note->remoteActorId,
                'visibility'         => $note->visibility,
                'state'              => 'live',
                'snapshot_json'      => $note->snapshotJson,
                'snapshot_hash'      => $note->snapshotHash,
                'published_at'       => $note->publishedAt,
                'updated_at'         => $note->updatedAt,
                'created_at'         => $note->createdAt,
            ])
        ;

        return $this->findLocalNote($note->publicId)
            ?? throw new \RuntimeException('The inserted local ActivityPub Note cannot be reloaded.');
    }

    public function updateLocalNote(
        StoredLocalNoteRepresentation $note,
        string                        $snapshotJson,
        string                        $snapshotHash,
        int                           $updatedAt,
    ): StoredLocalNoteRepresentation {
        if (preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1
            || $updatedAt <= $note->updatedAt
        ) {
            throw new \InvalidArgumentException('An updated local ActivityPub Note snapshot is invalid.');
        }

        $affected = $this->dbLayer->update(ActivityPubSchema::LOCAL_NOTE_TABLE)
            ->set('snapshot_json', ':snapshot_json')->setParameter('snapshot_json', $snapshotJson)
            ->set('snapshot_hash', ':new_snapshot_hash')->setParameter('new_snapshot_hash', $snapshotHash)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $updatedAt)
            ->where('id = :id')->setParameter('id', $note->id)
            ->andWhere('state = :live')->setParameter('live', 'live')
            ->andWhere('snapshot_hash = :old_snapshot_hash')->setParameter('old_snapshot_hash', $note->snapshotHash)
            ->execute()
            ->affectedRows()
        ;
        if ($affected !== 1) {
            throw new \RuntimeException('The local ActivityPub Note changed concurrently during update.');
        }

        return $this->findLocalNote($note->publicId)
            ?? throw new \RuntimeException('The updated local ActivityPub Note cannot be reloaded.');
    }

    /**
     * @param non-empty-list<string> $targetUrls
     * @return array<string, list<StoredLocalNoteRepresentation>>
     */
    public function liveLocalNotesForTargets(int $actorId, array $targetUrls): array
    {
        if ($actorId < 1) {
            throw new \InvalidArgumentException('A local ActivityPub Note owner is invalid.');
        }

        $hashes = [];
        foreach ($targetUrls as $targetUrl) {
            if (!str_starts_with($targetUrl, 'https://') || \strlen($targetUrl) > 2_048) {
                throw new \InvalidArgumentException('A local ActivityPub Note target is invalid.');
            }

            $hashes[hash('sha256', $targetUrl)] = $targetUrl;
        }

        $rows = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::LOCAL_NOTE_TABLE)
            ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->andWhere('state = :state')->setParameter('state', 'live')
            ->andWhere('in_reply_to_hash IN (:target_hashes)')->setParameter('target_hashes', array_keys($hashes))
            ->orderBy('published_at, id')
            ->execute()
            ->fetchAssocAll()
        ;
        $result = [];
        foreach ($rows as $row) {
            $targetUrl = (string)$row['in_reply_to_url'];
            $hash = hash('sha256', $targetUrl);
            if (!isset($hashes[$hash]) || !hash_equals($hashes[$hash], $targetUrl)) {
                throw new \RuntimeException('A local ActivityPub Note target SHA-256 collision was detected.');
            }

            $result[$targetUrl][] = $this->hydrateLocalNote($row);
        }

        return $result;
    }

    public function tombstoneLocalNote(StoredLocalNoteRepresentation $note, int $deletedAt): StoredLocalNoteRepresentation
    {
        if ($deletedAt < 1) {
            throw new \InvalidArgumentException('A local ActivityPub Note deletion timestamp must be positive.');
        }

        $affected = $this->dbLayer->update(ActivityPubSchema::LOCAL_NOTE_TABLE)
            ->set('state', ':state')->setParameter('state', 'tombstoned')
            ->set('deleted_at', ':deleted_at')->setParameter('deleted_at', $deletedAt)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', max($deletedAt, $note->updatedAt))
            ->where('id = :id')->setParameter('id', $note->id)
            ->andWhere('state = :live')->setParameter('live', 'live')
            ->andWhere('snapshot_hash = :snapshot_hash')->setParameter('snapshot_hash', $note->snapshotHash)
            ->execute()
            ->affectedRows()
        ;
        if ($affected !== 1) {
            throw new \RuntimeException('The local ActivityPub Note changed concurrently during deletion.');
        }

        return $this->findLocalNote($note->publicId)
            ?? throw new \RuntimeException('The tombstoned local ActivityPub Note cannot be reloaded.');
    }

    public function nextIncarnation(ContentId $contentId): int
    {
        $latest = (int)$this->dbLayer->select('MAX(incarnation)')
            ->from(ActivityPubSchema::OBJECT_TABLE)
            ->where('local_type = :local_type')->setParameter('local_type', $contentId->type->value)
            ->andWhere('local_id = :local_id')->setParameter('local_id', $contentId->value)
            ->execute()
            ->result()
        ;

        return $latest + 1;
    }

    public function insertObject(NewStoredObject $object): StoredObjectRepresentation
    {
        $this->dbLayer->insert(ActivityPubSchema::OBJECT_TABLE)
            ->values([
                'public_id'          => ':public_id',
                'local_type'         => ':local_type',
                'local_id'           => ':local_id',
                'incarnation'        => ':incarnation',
                'owner_actor_id'     => ':owner_actor_id',
                'object_type'        => ':object_type',
                'visibility'         => ':visibility',
                'state'              => ':state',
                'canonical_url'      => ':canonical_url',
                'canonical_url_hash' => ':canonical_url_hash',
                'snapshot_json'      => ':snapshot_json',
                'snapshot_hash'      => ':snapshot_hash',
                'published_at'       => ':published_at',
                'updated_at'         => ':updated_at',
                'broadcast_at'       => ':broadcast_at',
                'featured_at'        => ':featured_at',
                'created_at'         => ':created_at',
            ])
            ->execute([
                'public_id'          => $object->publicId,
                'local_type'         => $object->contentId->type->value,
                'local_id'           => $object->contentId->value,
                'incarnation'        => $object->incarnation,
                'owner_actor_id'     => $object->ownerActorId,
                'object_type'        => $object->objectType,
                'visibility'         => $object->visibility,
                'state'              => 'live',
                'canonical_url'      => $object->canonicalUrl,
                'canonical_url_hash' => hash('sha256', $object->canonicalUrl),
                'snapshot_json'      => $object->snapshotJson,
                'snapshot_hash'      => $object->snapshotHash,
                'published_at'       => $object->publishedAt,
                'updated_at'         => $object->updatedAt,
                'broadcast_at'       => $object->broadcastAt,
                'featured_at'        => $object->featuredAt,
                'created_at'         => $object->createdAt,
            ])
        ;

        return $this->findObject($object->publicId)
            ?? throw new \RuntimeException('The inserted ActivityPub object cannot be reloaded.');
    }

    public function updateObject(
        StoredObjectRepresentation $current,
        string                     $canonicalUrl,
        string                     $snapshotJson,
        string                     $snapshotHash,
        int                        $updatedAt,
        string                     $visibility,
        ?int                       $broadcastAt,
    ): StoredObjectRepresentation {
        if (!str_starts_with($canonicalUrl, 'https://')
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1
            || $updatedAt < 1
            || !\in_array($visibility, ['public', 'unlisted'], true)
            || ($broadcastAt !== $current->broadcastAt
                && ($current->broadcastAt !== null || $broadcastAt === null || $broadcastAt < 1))
        ) {
            throw new \InvalidArgumentException('The updated ActivityPub object snapshot is invalid.');
        }

        $affectedRows = $this->dbLayer->update(ActivityPubSchema::OBJECT_TABLE)
            ->set('canonical_url', ':canonical_url')->setParameter('canonical_url', $canonicalUrl)
            ->set('canonical_url_hash', ':canonical_url_hash')->setParameter('canonical_url_hash', hash('sha256', $canonicalUrl))
            ->set('snapshot_json', ':snapshot_json')->setParameter('snapshot_json', $snapshotJson)
            ->set('snapshot_hash', ':new_snapshot_hash')->setParameter('new_snapshot_hash', $snapshotHash)
            ->set('visibility', ':visibility')->setParameter('visibility', $visibility)
            ->set('broadcast_at', ':broadcast_at')->setParameter('broadcast_at', $broadcastAt)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $updatedAt)
            ->where('id = :id')->setParameter('id', $current->id)
            ->andWhere('state = :state')->setParameter('state', 'live')
            ->andWhere('snapshot_hash = :old_snapshot_hash')->setParameter('old_snapshot_hash', $current->snapshotHash)
            ->execute()
            ->affectedRows()
        ;
        if ($affectedRows !== 1) {
            throw new \RuntimeException('The ActivityPub object changed concurrently; retry the editorial operation.');
        }

        return $this->findObject($current->publicId)
            ?? throw new \RuntimeException('The updated ActivityPub object cannot be reloaded.');
    }

    public function markObjectBroadcastStarted(
        StoredObjectRepresentation $current,
        int                        $broadcastAt,
    ): StoredObjectRepresentation {
        if ($broadcastAt < 1) {
            throw new \InvalidArgumentException('An ActivityPub broadcast timestamp must be positive.');
        }

        if ($current->broadcastAt !== null) {
            return $current;
        }

        $affectedRows = $this->dbLayer->update(ActivityPubSchema::OBJECT_TABLE)
            ->set('broadcast_at', ':broadcast_at')->setParameter('broadcast_at', $broadcastAt)
            ->where('id = :id')->setParameter('id', $current->id)
            ->andWhere('state = :state')->setParameter('state', 'live')
            ->andWhere('snapshot_hash = :snapshot_hash')->setParameter('snapshot_hash', $current->snapshotHash)
            ->andWhere('broadcast_at IS NULL')
            ->execute()
            ->affectedRows()
        ;
        if ($affectedRows !== 1) {
            throw new \RuntimeException('The ActivityPub object changed concurrently before its first broadcast.');
        }

        return $this->findObject($current->publicId)
            ?? throw new \RuntimeException('The broadcast ActivityPub object cannot be reloaded.');
    }

    public function setObjectFeatured(
        StoredObjectRepresentation $current,
        bool                       $featured,
        int                        $now,
    ): StoredObjectRepresentation {
        if ($now < 1) {
            throw new \InvalidArgumentException('An ActivityPub featured transition timestamp must be positive.');
        }

        if (($current->featuredAt !== null) === $featured) {
            return $current;
        }

        $query = $this->dbLayer->update(ActivityPubSchema::OBJECT_TABLE)
            ->set('featured_at', ':featured_at')->setParameter('featured_at', $featured ? $now : null)
            ->where('id = :id')->setParameter('id', $current->id)
            ->andWhere('state = :state')->setParameter('state', 'live')
            ->andWhere('snapshot_hash = :snapshot_hash')->setParameter('snapshot_hash', $current->snapshotHash)
        ;
        if ($featured) {
            $query->andWhere('featured_at IS NULL');
        } else {
            $query->andWhere('featured_at = :old_featured_at')->setParameter('old_featured_at', $current->featuredAt);
        }

        if ($query->execute()->affectedRows() !== 1) {
            throw new \RuntimeException('The ActivityPub object changed concurrently during its featured transition.');
        }

        return $this->findObject($current->publicId)
            ?? throw new \RuntimeException('The featured ActivityPub object cannot be reloaded.');
    }

    public function tombstoneObject(StoredObjectRepresentation $current, int $deletedAt): StoredObjectRepresentation
    {
        if ($deletedAt < 1) {
            throw new \InvalidArgumentException('An ActivityPub deletion timestamp must be positive.');
        }

        $affectedRows = $this->dbLayer->update(ActivityPubSchema::OBJECT_TABLE)
            ->set('state', ':tombstoned')->setParameter('tombstoned', 'tombstoned')
            ->set('deleted_at', ':deleted_at')->setParameter('deleted_at', $deletedAt)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $deletedAt)
            ->where('id = :id')->setParameter('id', $current->id)
            ->andWhere('state = :live')->setParameter('live', 'live')
            ->andWhere('snapshot_hash = :snapshot_hash')->setParameter('snapshot_hash', $current->snapshotHash)
            ->execute()
            ->affectedRows()
        ;
        if ($affectedRows !== 1) {
            throw new \RuntimeException('The ActivityPub object changed concurrently; retry the deletion.');
        }

        return $this->findObject($current->publicId)
            ?? throw new \RuntimeException('The tombstoned ActivityPub object cannot be reloaded.');
    }

    public function findActivity(string $publicId): ?StoredActivityRepresentation
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::ACTIVITY_TABLE)
            ->where('public_id = :public_id')->setParameter('public_id', $publicId)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateActivity($row) : null;
    }

    public function findActivityById(int $id): ?StoredActivityRepresentation
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::ACTIVITY_TABLE)
            ->where('id = :id')->setParameter('id', $id)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateActivity($row) : null;
    }

    public function findActivityByDeduplicationKey(string $key): ?StoredActivityRepresentation
    {
        if ($key === '' || \strlen($key) > 128) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::ACTIVITY_TABLE)
            ->where('deduplication_key = :key')->setParameter('key', $key)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrateActivity($row) : null;
    }

    public function insertActivity(NewStoredActivity $activity): StoredActivityRepresentation
    {
        $this->dbLayer->insert(ActivityPubSchema::ACTIVITY_TABLE)
            ->values([
                'public_id'          => ':public_id',
                'actor_id'           => ':actor_id',
                'object_id'          => ':object_id',
                'local_note_id'      => ':local_note_id',
                'activity_type'      => ':activity_type',
                'visibility'         => ':visibility',
                'delivery_intent'    => ':delivery_intent',
                'deduplication_key'  => ':deduplication_key',
                'serialized_body'    => ':serialized_body',
                'body_hash'          => ':body_hash',
                'published_at'       => ':published_at',
                'created_at'         => ':created_at',
            ])
            ->execute([
                'public_id'          => $activity->publicId,
                'actor_id'           => $activity->actorId,
                'object_id'          => $activity->objectId,
                'local_note_id'      => $activity->localNoteId,
                'activity_type'      => $activity->type,
                'visibility'         => $activity->visibility,
                'delivery_intent'    => $activity->deliveryIntent->value,
                'deduplication_key'  => $activity->deduplicationKey,
                'serialized_body'    => $activity->serializedBody,
                'body_hash'          => $activity->bodyHash,
                'published_at'       => $activity->publishedAt,
                'created_at'         => $activity->createdAt,
            ])
        ;

        return $this->findActivity($activity->publicId)
            ?? throw new \RuntimeException('The inserted ActivityPub activity cannot be reloaded.');
    }

    /** @return list<StoredActivityRepresentation> */
    public function outboxPage(int $actorId, ?CollectionAnchor $before, int $limit): array
    {
        $query = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::ACTIVITY_TABLE)
            ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->orderBy('published_at DESC, id DESC')
            ->limit($this->boundedLimit($limit))
        ;
        if ($before instanceof CollectionAnchor) {
            $query->andWhere('(published_at < :before_time OR (published_at = :before_time AND id < :before_id))')
                ->setParameter('before_time', $before->timestamp)
                ->setParameter('before_id', $before->id)
            ;
        }

        $result     = $query->execute();
        $activities = [];
        while ($row = $result->fetchAssoc()) {
            $activities[] = $this->hydrateActivity($row);
        }

        return $activities;
    }

    /** @return list<StoredObjectRepresentation> */
    public function featuredPage(int $actorId, ?CollectionAnchor $before, int $limit): array
    {
        $query = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::OBJECT_TABLE)
            ->where('owner_actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->andWhere('state = :state')->setParameter('state', 'live')
            ->andWhere('featured_at IS NOT NULL')
            ->orderBy('featured_at DESC, id DESC')
            ->limit($this->boundedLimit($limit))
        ;
        if ($before instanceof CollectionAnchor) {
            $query->andWhere('(featured_at < :before_time OR (featured_at = :before_time AND id < :before_id))')
                ->setParameter('before_time', $before->timestamp)
                ->setParameter('before_id', $before->id)
            ;
        }

        $result  = $query->execute();
        $objects = [];
        while ($row = $result->fetchAssoc()) {
            $objects[] = $this->hydrateObject($row);
        }

        return $objects;
    }

    public function outboxCount(int $actorId): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::ACTIVITY_TABLE)
            ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->execute()
            ->result()
        ;
    }

    public function featuredCount(int $actorId): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::OBJECT_TABLE)
            ->where('owner_actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->andWhere('state = :state')->setParameter('state', 'live')
            ->andWhere('featured_at IS NOT NULL')
            ->execute()
            ->result()
        ;
    }

    public function localObjectCount(): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::OBJECT_TABLE)
            ->where('state = :state')->setParameter('state', 'live')
            ->execute()
            ->result()
        ;
    }

    public function followCount(int $actorId, string $direction): int
    {
        if (!\in_array($direction, ['incoming', 'outgoing'], true)) {
            throw new \InvalidArgumentException('The ActivityPub follow direction is invalid.');
        }

        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::FOLLOW_TABLE)
            ->where('local_actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->andWhere('direction = :direction')->setParameter('direction', $direction)
            ->andWhere('state = :state')->setParameter('state', 'accepted')
            ->execute()
            ->result()
        ;
    }

    /** @param array<string, mixed> $row */
    private function hydrateObject(array $row): StoredObjectRepresentation
    {
        try {
            return new StoredObjectRepresentation(
                (int)$row['id'],
                (string)$row['public_id'],
                new ContentId(ContentType::from((string)$row['local_type']), (int)$row['local_id']),
                (int)$row['incarnation'],
                (int)$row['owner_actor_id'],
                (string)$row['object_type'],
                (string)$row['visibility'],
                (string)$row['state'],
                (string)$row['canonical_url'],
                (string)$row['snapshot_json'],
                (string)$row['snapshot_hash'],
                (int)$row['published_at'],
                (int)$row['updated_at'],
                $row['deleted_at'] === null ? null : (int)$row['deleted_at'],
                $row['featured_at'] === null ? null : (int)$row['featured_at'],
                $row['broadcast_at'] === null ? null : (int)$row['broadcast_at'],
            );
        } catch (\ValueError | \InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored local ActivityPub object is invalid.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrateActivity(array $row): StoredActivityRepresentation
    {
        try {
            return new StoredActivityRepresentation(
                (int)$row['id'],
                (string)$row['public_id'],
                (int)$row['actor_id'],
                $row['object_id'] === null ? null : (int)$row['object_id'],
                (string)$row['activity_type'],
                (string)$row['visibility'],
                ActivityDeliveryIntent::from((string)$row['delivery_intent']),
                (string)$row['serialized_body'],
                (string)$row['body_hash'],
                (int)$row['published_at'],
                $row['local_note_id'] === null ? null : (int)$row['local_note_id'],
            );
        } catch (\ValueError $exception) {
            throw new \RuntimeException('A stored local ActivityPub activity is invalid.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrateLocalNote(array $row): StoredLocalNoteRepresentation
    {
        try {
            return new StoredLocalNoteRepresentation(
                (int)$row['id'],
                (string)$row['public_id'],
                (int)$row['actor_id'],
                (string)$row['in_reply_to_url'],
                (int)$row['remote_actor_id'],
                (string)$row['visibility'],
                (string)$row['state'],
                (string)$row['snapshot_json'],
                (string)$row['snapshot_hash'],
                (int)$row['published_at'],
                (int)$row['updated_at'],
                $row['deleted_at'] === null ? null : (int)$row['deleted_at'],
            );
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored local ActivityPub Note is invalid.', 0, $exception);
        }
    }

    private function boundedLimit(int $limit): int
    {
        if ($limit < 1 || $limit > 101) {
            throw new \InvalidArgumentException('An ActivityPub collection query limit must be between 1 and 101.');
        }

        return $limit;
    }
}
