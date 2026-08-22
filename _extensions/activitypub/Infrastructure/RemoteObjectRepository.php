<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\RemoteObject;
use Register\Extension\activitypub\Presentation\CanonicalJson;

/** Ownership-enforcing storage for verified, sanitized remote ActivityStreams objects. */
final readonly class RemoteObjectRepository
{
    private const int SNAPSHOT_RETENTION_SECONDS = 90 * 24 * 60 * 60;

    public function __construct(private DbLayer $dbLayer, private CanonicalJson $canonicalJson)
    {
    }

    public function findByUrl(string $objectUrl): ?RemoteObject
    {
        $row = $this->rowByUrl($objectUrl);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findById(int $id): ?RemoteObject
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::REMOTE_OBJECT_TABLE)
            ->where('id = :id')->setParameter('id', $id)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<int, string> $localRecipients */
    public function create(
        ValidatedRemoteObject $object,
        int                   $ownerActorId,
        array                 $localRecipients,
        int                   $now,
    ): RemoteObject {
        $this->validateIdentity($ownerActorId, $localRecipients, $now);
        $existing = $this->findByUrl($object->objectUrl);
        if ($existing instanceof RemoteObject) {
            $this->assertOwner($existing, $ownerActorId);
            if ($existing->state !== 'live') {
                throw new \DomainException('A deleted remote ActivityPub object cannot be recreated.');
            }

            $snapshot = $this->snapshot($existing);
            $bodyHash = $this->documentHash($object);
            if (!hash_equals($snapshot->bodyHash, $bodyHash)) {
                throw new \DomainException('A remote ActivityPub Create cannot mutate an existing object; Update is required.');
            }

            return $existing;
        }

        $this->dbLayer->insert(ActivityPubSchema::REMOTE_OBJECT_TABLE)
            ->values([
                'url_hash'             => ':url_hash',
                'object_url'           => ':object_url',
                'owner_actor_id'       => ':owner_actor_id',
                'object_type'          => ':object_type',
                'in_reply_to_hash'     => ':in_reply_to_hash',
                'in_reply_to_url'      => ':in_reply_to_url',
                'visibility'           => ':visibility',
                'state'                => ':state',
                'published_at'         => ':published_at',
                'remote_updated_at'    => ':remote_updated_at',
                'fetched_at'           => ':fetched_at',
                'created_at'           => ':created_at',
                'updated_at'           => ':updated_at',
            ])
            ->onConflictDoNothing('url_hash')
            ->execute([
                'url_hash'              => hash('sha256', $object->objectUrl),
                'object_url'            => $object->objectUrl,
                'owner_actor_id'        => $ownerActorId,
                'object_type'           => $object->objectType,
                'in_reply_to_hash'      => $object->inReplyToUrl === null ? null : hash('sha256', $object->inReplyToUrl),
                'in_reply_to_url'       => $object->inReplyToUrl,
                'visibility'            => $object->visibility,
                'state'                 => 'live',
                'published_at'          => $object->publishedAt,
                'remote_updated_at'     => $object->updatedAt,
                'fetched_at'            => $now,
                'created_at'            => $now,
                'updated_at'            => $now,
            ])
        ;
        $row = $this->rowByUrl($object->objectUrl);
        if ($row === null) {
            throw new \RuntimeException('The remote ActivityPub object could not be stored.');
        }

        $id = (int)$row['id'];
        if ((int)$row['owner_actor_id'] !== $ownerActorId) {
            throw new \DomainException('The remote ActivityPub object URL is already owned by another actor.');
        }

        if ($row['current_snapshot_id'] !== null) {
            $stored = $this->hydrate($row);
            $snapshot = $this->snapshot($stored);
            if (!hash_equals($snapshot->bodyHash, $this->documentHash($object))) {
                throw new \DomainException('A conflicting remote ActivityPub object Create was already stored.');
            }

            return $stored;
        }

        $snapshotId = $this->storeSnapshot($id, $object, $now);
        $this->dbLayer->update(ActivityPubSchema::REMOTE_OBJECT_TABLE)
            ->set('current_snapshot_id', ':snapshot_id')->setParameter('snapshot_id', $snapshotId)
            ->where('id = :id')->setParameter('id', $id)
            ->andWhere('current_snapshot_id IS NULL')
            ->execute()
        ;
        $this->replaceRecipients($id, $localRecipients, $now);

        return $this->findByUrl($object->objectUrl)
            ?? throw new \RuntimeException('The stored remote ActivityPub object cannot be reloaded.');
    }

    /** @param array<int, string> $localRecipients */
    public function update(ValidatedRemoteObject $object, int $ownerActorId, array $localRecipients, int $now): RemoteObject
    {
        $existing = $this->findByUrl($object->objectUrl);
        if (!$existing instanceof RemoteObject) {
            throw new \DomainException('The remote ActivityPub Update references no live stored object.');
        }

        if ($existing->state !== 'live') {
            throw new \DomainException('The remote ActivityPub Update references no live stored object.');
        }

        $this->assertOwner($existing, $ownerActorId);
        if ($object->objectType !== $existing->objectType) {
            throw new \DomainException('A remote ActivityPub Update cannot change an object type.');
        }

        if ($object->inReplyToUrl !== $existing->inReplyToUrl) {
            throw new \DomainException('A remote ActivityPub Update cannot move an object to another conversation.');
        }

        if ($existing->remoteUpdatedAt !== null && $object->updatedAt < $existing->remoteUpdatedAt) {
            throw new \DomainException('A stale remote ActivityPub Update was rejected.');
        }

        $currentSnapshot = $this->snapshot($existing);
        if (hash_equals($currentSnapshot->bodyHash, $this->documentHash($object))) {
            $this->replaceRecipients($existing->id, $localRecipients, $now);
            return $existing;
        }

        $snapshotId = $this->storeSnapshot($existing->id, $object, $now);
        $this->dbLayer->update(ActivityPubSchema::REMOTE_OBJECT_TABLE)
            ->set('current_snapshot_id', ':snapshot_id')->setParameter('snapshot_id', $snapshotId)
            ->set('in_reply_to_hash', ':in_reply_to_hash')->setParameter(
                'in_reply_to_hash',
                $object->inReplyToUrl === null ? null : hash('sha256', $object->inReplyToUrl),
            )
            ->set('in_reply_to_url', ':in_reply_to_url')->setParameter('in_reply_to_url', $object->inReplyToUrl)
            ->set('visibility', ':visibility')->setParameter('visibility', $object->visibility)
            ->set('remote_updated_at', ':remote_updated_at')->setParameter('remote_updated_at', $object->updatedAt)
            ->set('fetched_at', ':fetched_at')->setParameter('fetched_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $existing->id)
            ->andWhere('owner_actor_id = :owner_actor_id')->setParameter('owner_actor_id', $ownerActorId)
            ->andWhere('state = :state')->setParameter('state', 'live')
            ->andWhere('current_snapshot_id = :old_snapshot_id')->setParameter('old_snapshot_id', $existing->currentSnapshotId)
            ->execute()
        ;

        $updated = $this->findByUrl($object->objectUrl);
        if (!$updated instanceof RemoteObject) {
            throw new \RuntimeException('The remote ActivityPub object changed concurrently during Update.');
        }

        if ($updated->currentSnapshotId !== $snapshotId) {
            throw new \RuntimeException('The remote ActivityPub object changed concurrently during Update.');
        }

        $this->replaceRecipients($existing->id, $localRecipients, $now);

        return $updated;
    }

    public function delete(string $objectUrl, int $ownerActorId, int $now): ?RemoteObject
    {
        $existing = $this->findByUrl($objectUrl);
        if (!$existing instanceof RemoteObject) {
            return null;
        }

        $this->assertOwner($existing, $ownerActorId);
        if ($existing->state === 'deleted') {
            return $existing;
        }

        $this->dbLayer->update(ActivityPubSchema::REMOTE_OBJECT_TABLE)
            ->set('state', ':state')->setParameter('state', 'deleted')
            ->set('deleted_at', ':deleted_at')->setParameter('deleted_at', $now)
            ->set('featured_at', 'NULL')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $existing->id)
            ->andWhere('owner_actor_id = :owner_actor_id')->setParameter('owner_actor_id', $ownerActorId)
            ->andWhere('state = :live')->setParameter('live', 'live')
            ->execute()
        ;

        return $this->findByUrl($objectUrl)
            ?? throw new \RuntimeException('The deleted remote ActivityPub object cannot be reloaded.');
    }

    public function setFeatured(
        RemoteObject $object,
        int          $ownerActorId,
        bool         $featured,
        int          $now,
    ): RemoteObject {
        if ($now < 1) {
            throw new \InvalidArgumentException('A remote ActivityPub featured transition timestamp must be positive.');
        }

        $this->assertOwner($object, $ownerActorId);
        if ($object->state !== 'live') {
            throw new \DomainException('A deleted remote ActivityPub object cannot change featured state.');
        }

        if (($object->featuredAt !== null) === $featured) {
            return $object;
        }

        $query = $this->dbLayer->update(ActivityPubSchema::REMOTE_OBJECT_TABLE)
            ->set('featured_at', ':featured_at')->setParameter('featured_at', $featured ? $now : null)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $object->id)
            ->andWhere('owner_actor_id = :owner_actor_id')->setParameter('owner_actor_id', $ownerActorId)
            ->andWhere('state = :state')->setParameter('state', 'live')
        ;
        if ($featured) {
            $query->andWhere('featured_at IS NULL');
        } else {
            $query->andWhere('featured_at = :old_featured_at')->setParameter('old_featured_at', $object->featuredAt);
        }

        if ($query->execute()->affectedRows() !== 1) {
            throw new \RuntimeException('The remote ActivityPub object changed concurrently during its featured transition.');
        }

        return $this->findById($object->id)
            ?? throw new \RuntimeException('The featured remote ActivityPub object cannot be reloaded.');
    }

    public function snapshot(RemoteObject $object): RemoteObjectSnapshot
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE)
            ->where('id = :id')->setParameter('id', $object->currentSnapshotId)
            ->andWhere('subject_type = :subject_type')->setParameter('subject_type', 'object')
            ->andWhere('subject_id = :subject_id')->setParameter('subject_id', $object->id)
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            throw new \RuntimeException('The current remote ActivityPub object snapshot is missing.');
        }

        try {
            $document = json_decode((string)$row['document_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('A stored remote ActivityPub object snapshot is invalid JSON.', 0, $exception);
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new \RuntimeException('A stored remote ActivityPub object snapshot is not a JSON object.');
        }

        return new RemoteObjectSnapshot(
            (int)$row['id'],
            (int)$row['subject_id'],
            (string)$row['body_hash'],
            $document,
            (int)$row['fetched_at'],
        );
    }

    /** @return array<int, string> Local actor id to recipient kind. */
    public function recipients(RemoteObject $object): array
    {
        $rows = $this->dbLayer->select('local_actor_id', 'recipient_kind')
            ->from(ActivityPubSchema::REMOTE_RECIPIENT_TABLE)
            ->where('remote_object_id = :remote_object_id')->setParameter('remote_object_id', $object->id)
            ->orderBy('local_actor_id')
            ->execute()
            ->fetchAssocAll()
        ;
        $result = [];
        foreach ($rows as $row) {
            $actorId = (int)$row['local_actor_id'];
            $kind    = (string)$row['recipient_kind'];
            if ($actorId < 1 || !\in_array($kind, ['addressed', 'inbox', 'following'], true)) {
                throw new \RuntimeException('A stored remote ActivityPub recipient is invalid.');
            }

            $result[$actorId] = $kind;
        }

        return $result;
    }

    public function isVisibleToLocalActor(RemoteObject $object, int $localActorId): bool
    {
        if ($localActorId < 1 || $object->state !== 'live') {
            return false;
        }

        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::REMOTE_RECIPIENT_TABLE)
            ->where('remote_object_id = :remote_object_id')->setParameter('remote_object_id', $object->id)
            ->andWhere('local_actor_id = :local_actor_id')->setParameter('local_actor_id', $localActorId)
            ->execute()
            ->result() === 1
        ;
    }

    /** @return array<string, mixed>|null */
    private function rowByUrl(string $objectUrl): ?array
    {
        if (!str_starts_with($objectUrl, 'https://') || \strlen($objectUrl) > 2_048) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::REMOTE_OBJECT_TABLE)
            ->where('url_hash = :url_hash')->setParameter('url_hash', hash('sha256', $objectUrl))
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            return null;
        }

        if (!hash_equals((string)$row['object_url'], $objectUrl)) {
            throw new \RuntimeException('A remote ActivityPub object URL SHA-256 collision was detected.');
        }

        return $row;
    }

    private function storeSnapshot(int $objectId, ValidatedRemoteObject $object, int $now): int
    {
        $json = $this->canonicalJson->encode($object->sanitizedDocument);
        $hash = hash('sha256', $json);
        $this->dbLayer->insert(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE)
            ->values([
                'subject_type'       => ':subject_type',
                'subject_id'         => ':subject_id',
                'body_hash'          => ':body_hash',
                'document_json'      => ':document_json',
                'verification_state' => ':verification_state',
                'fetched_at'         => ':fetched_at',
                'retain_until'       => ':retain_until',
            ])
            ->onConflictDoNothing('subject_type', 'subject_id', 'body_hash')
            ->execute([
                'subject_type'       => 'object',
                'subject_id'         => $objectId,
                'body_hash'          => $hash,
                'document_json'      => $json,
                'verification_state' => 'signed',
                'fetched_at'         => $now,
                'retain_until'       => $now + self::SNAPSHOT_RETENTION_SECONDS,
            ])
        ;
        $id = $this->dbLayer->select('id')
            ->from(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE)
            ->where('subject_type = :subject_type')->setParameter('subject_type', 'object')
            ->andWhere('subject_id = :subject_id')->setParameter('subject_id', $objectId)
            ->andWhere('body_hash = :body_hash')->setParameter('body_hash', $hash)
            ->execute()
            ->result()
        ;
        if ($id === false || (int)$id < 1) {
            throw new \RuntimeException('The remote ActivityPub object snapshot could not be stored.');
        }

        return (int)$id;
    }

    private function documentHash(ValidatedRemoteObject $object): string
    {
        return hash('sha256', $this->canonicalJson->encode($object->sanitizedDocument));
    }

    private function assertOwner(RemoteObject $object, int $ownerActorId): void
    {
        if ($object->ownerActorId !== $ownerActorId) {
            throw new \DomainException('The verified ActivityPub actor does not own the remote object.');
        }
    }

    /** @param array<int, string> $localRecipients */
    private function validateIdentity(int $ownerActorId, array $localRecipients, int $now): void
    {
        if ($ownerActorId < 1 || $now < 1 || \count($localRecipients) > 128) {
            throw new \InvalidArgumentException('The remote ActivityPub object storage identity is invalid.');
        }

        foreach ($localRecipients as $actorId => $kind) {
            if ($actorId < 1 || !\in_array($kind, ['addressed', 'inbox', 'following'], true)) {
                throw new \InvalidArgumentException('A remote ActivityPub object recipient is invalid.');
            }
        }
    }

    /** @param array<int, string> $localRecipients */
    private function replaceRecipients(int $objectId, array $localRecipients, int $now): void
    {
        $this->dbLayer->delete(ActivityPubSchema::REMOTE_RECIPIENT_TABLE)
            ->where('remote_object_id = :remote_object_id')->setParameter('remote_object_id', $objectId)
            ->execute()
        ;
        foreach ($localRecipients as $actorId => $kind) {
            $this->dbLayer->insert(ActivityPubSchema::REMOTE_RECIPIENT_TABLE)
                ->values([
                    'remote_object_id' => ':remote_object_id',
                    'local_actor_id'   => ':local_actor_id',
                    'recipient_kind'   => ':recipient_kind',
                    'created_at'       => ':created_at',
                ])
                ->execute([
                    'remote_object_id' => $objectId,
                    'local_actor_id'   => $actorId,
                    'recipient_kind'   => $kind,
                    'created_at'       => $now,
                ])
            ;
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): RemoteObject
    {
        if ($row['current_snapshot_id'] === null) {
            throw new \RuntimeException('A stored remote ActivityPub object has no current snapshot.');
        }

        try {
            return new RemoteObject(
                (int)$row['id'],
                (string)$row['object_url'],
                (int)$row['owner_actor_id'],
                (string)$row['object_type'],
                $row['in_reply_to_url'] === null ? null : (string)$row['in_reply_to_url'],
                (string)$row['visibility'],
                (string)$row['state'],
                (int)$row['current_snapshot_id'],
                $row['published_at'] === null ? null : (int)$row['published_at'],
                $row['remote_updated_at'] === null ? null : (int)$row['remote_updated_at'],
                $row['deleted_at'] === null ? null : (int)$row['deleted_at'],
                (int)$row['fetched_at'],
                $row['featured_at'] === null ? null : (int)$row['featured_at'],
            );
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored remote ActivityPub object is invalid.', 0, $exception);
        }
    }
}
