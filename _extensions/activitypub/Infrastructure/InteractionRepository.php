<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\RemoteInteraction;
use Register\Extension\activitypub\Presentation\CanonicalJson;

final readonly class InteractionRepository
{
    public function __construct(private DbLayer $dbLayer, private CanonicalJson $canonicalJson)
    {
    }

    public function findByActivityUrl(string $activityUrl): ?RemoteInteraction
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::INTERACTION_TABLE)
            ->where('remote_activity_hash = :hash')->setParameter('hash', hash('sha256', $activityUrl))
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            return null;
        }

        if (!hash_equals((string)$row['remote_activity_url'], $activityUrl)) {
            throw new \RuntimeException('A remote ActivityPub interaction URL SHA-256 collision was detected.');
        }

        return $this->hydrate($row);
    }

    public function findReplyByObjectUrl(string $objectUrl): ?RemoteInteraction
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::INTERACTION_TABLE)
            ->where('interaction_type = :type')->setParameter('type', 'reply')
            ->andWhere('remote_object_hash = :hash')->setParameter('hash', hash('sha256', $objectUrl))
            ->andWhere('remote_object_url = :url')->setParameter('url', $objectUrl)
            ->andWhere('state = :state')->setParameter('state', 'active')
            ->orderBy('id DESC')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function findActiveByObjectUrl(string $objectUrl): ?RemoteInteraction
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::INTERACTION_TABLE)
            ->where('remote_object_hash = :hash')->setParameter('hash', hash('sha256', $objectUrl))
            ->andWhere('remote_object_url = :url')->setParameter('url', $objectUrl)
            ->andWhere('state = :state')->setParameter('state', 'active')
            ->orderBy('id DESC')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(NewRemoteInteraction $interaction): RemoteInteraction
    {
        $existing = $this->findByActivityUrl($interaction->remoteActivityUrl);
        if ($existing instanceof RemoteInteraction) {
            $this->assertEquivalent($existing, $interaction);

            return $existing;
        }

        $this->dbLayer->insert(ActivityPubSchema::INTERACTION_TABLE)
            ->values([
                'interaction_type'     => ':interaction_type',
                'remote_actor_id'      => ':remote_actor_id',
                'remote_activity_hash' => ':remote_activity_hash',
                'remote_activity_url'  => ':remote_activity_url',
                'remote_object_hash'   => ':remote_object_hash',
                'remote_object_url'    => ':remote_object_url',
                'local_object_id'      => ':local_object_id',
                'local_note_id'        => ':local_note_id',
                'local_comment_id'     => ':local_comment_id',
                'reaction_source_key'  => ':reaction_source_key',
                'emoji'                => ':emoji',
                'is_public'            => ':is_public',
                'state'                => ':state',
                'provenance_json'      => ':provenance_json',
                'created_at'           => ':created_at',
                'updated_at'           => ':updated_at',
            ])
            ->onConflictDoNothing('remote_activity_hash')
            ->execute([
                'interaction_type'      => $interaction->type,
                'remote_actor_id'       => $interaction->remoteActorId,
                'remote_activity_hash'  => hash('sha256', $interaction->remoteActivityUrl),
                'remote_activity_url'   => $interaction->remoteActivityUrl,
                'remote_object_hash'    => $interaction->remoteObjectUrl === null
                    ? null
                    : hash('sha256', $interaction->remoteObjectUrl),
                'remote_object_url'     => $interaction->remoteObjectUrl,
                'local_object_id'       => $interaction->localObjectId,
                'local_note_id'         => $interaction->localNoteId,
                'local_comment_id'      => $interaction->localCommentId,
                'reaction_source_key'   => $interaction->reactionSourceKey,
                'emoji'                 => $interaction->emoji,
                'is_public'             => 0,
                'state'                 => 'active',
                'provenance_json'       => $this->canonicalJson->encode($interaction->provenance),
                'created_at'            => $interaction->createdAt,
                'updated_at'            => $interaction->createdAt,
            ])
        ;
        $stored = $this->findByActivityUrl($interaction->remoteActivityUrl);
        if (!$stored instanceof RemoteInteraction) {
            throw new \RuntimeException('The remote ActivityPub interaction could not be stored.');
        }

        $this->assertEquivalent($stored, $interaction);

        return $stored;
    }

    public function setReplyPublicByComment(int $commentId, bool $isPublic, int $now): bool
    {
        if ($commentId < 1 || $now < 1) {
            throw new \InvalidArgumentException('A public remote reply transition is invalid.');
        }

        return $this->dbLayer->update(ActivityPubSchema::INTERACTION_TABLE)
            ->set('is_public', $isPublic ? '1' : '0')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('interaction_type = :type')->setParameter('type', 'reply')
            ->andWhere('local_comment_id = :comment_id')->setParameter('comment_id', $commentId)
            ->andWhere('state = :state')->setParameter('state', 'active')
            ->execute()
            ->affectedRows() > 0
        ;
    }

    public function publicReplyCount(int $localObjectId): int
    {
        if ($localObjectId < 1) {
            return 0;
        }

        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::INTERACTION_TABLE)
            ->where('local_object_id = :local_object_id')->setParameter('local_object_id', $localObjectId)
            ->andWhere('interaction_type = :type')->setParameter('type', 'reply')
            ->andWhere('is_public = 1')
            ->andWhere('state = :state')->setParameter('state', 'active')
            ->execute()
            ->result()
        ;
    }

    public function setLocalNoteReplyPublic(int $localNoteId, string $remoteObjectUrl, bool $isPublic, int $now): bool
    {
        if ($localNoteId < 1 || !str_starts_with($remoteObjectUrl, 'https://') || $now < 1) {
            throw new \InvalidArgumentException('A public local-Note reply transition is invalid.');
        }

        return $this->dbLayer->update(ActivityPubSchema::INTERACTION_TABLE)
            ->set('is_public', $isPublic ? '1' : '0')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('interaction_type = :type')->setParameter('type', 'reply')
            ->andWhere('local_note_id = :local_note_id')->setParameter('local_note_id', $localNoteId)
            ->andWhere('remote_object_hash = :remote_object_hash')
            ->setParameter('remote_object_hash', hash('sha256', $remoteObjectUrl))
            ->andWhere('remote_object_url = :remote_object_url')->setParameter('remote_object_url', $remoteObjectUrl)
            ->andWhere('state = :state')->setParameter('state', 'active')
            ->execute()
            ->affectedRows() > 0
        ;
    }

    public function publicLocalNoteReplyCount(int $localNoteId): int
    {
        if ($localNoteId < 1) {
            return 0;
        }

        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::INTERACTION_TABLE)
            ->where('local_note_id = :local_note_id')->setParameter('local_note_id', $localNoteId)
            ->andWhere('interaction_type = :type')->setParameter('type', 'reply')
            ->andWhere('is_public = 1')
            ->andWhere('state = :state')->setParameter('state', 'active')
            ->execute()
            ->result()
        ;
    }

    /** @return list<RemoteInteraction> */
    public function publicLocalNoteRepliesPage(
        int $localNoteId,
        ?\Register\Extension\activitypub\Domain\CollectionAnchor $before,
        int $limit,
    ): array {
        if ($localNoteId < 1 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('A public local-Note reply page is invalid.');
        }

        $query = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::INTERACTION_TABLE)
            ->where('local_note_id = :local_note_id')->setParameter('local_note_id', $localNoteId)
            ->andWhere('interaction_type = :type')->setParameter('type', 'reply')
            ->andWhere('is_public = 1')
            ->andWhere('state = :state')->setParameter('state', 'active')
            ->orderBy('created_at DESC, id DESC')
            ->limit($limit)
        ;
        if ($before instanceof \Register\Extension\activitypub\Domain\CollectionAnchor) {
            $query->andWhere('(created_at < :before_time OR (created_at = :before_time AND id < :before_id))')
                ->setParameter('before_time', $before->timestamp)
                ->setParameter('before_id', $before->id)
            ;
        }

        return array_values(array_map($this->hydrate(...), $query->execute()->fetchAssocAll()));
    }

    /** @return list<RemoteInteraction> */
    public function publicRepliesPage(int $localObjectId, ?\Register\Extension\activitypub\Domain\CollectionAnchor $before, int $limit): array
    {
        if ($localObjectId < 1 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('A public remote reply page is invalid.');
        }

        $query = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::INTERACTION_TABLE)
            ->where('local_object_id = :local_object_id')->setParameter('local_object_id', $localObjectId)
            ->andWhere('interaction_type = :type')->setParameter('type', 'reply')
            ->andWhere('is_public = 1')
            ->andWhere('state = :state')->setParameter('state', 'active')
            ->orderBy('created_at DESC, id DESC')
            ->limit($limit)
        ;
        if ($before instanceof \Register\Extension\activitypub\Domain\CollectionAnchor) {
            $query->andWhere('(created_at < :before_time OR (created_at = :before_time AND id < :before_id))')
                ->setParameter('before_time', $before->timestamp)
                ->setParameter('before_id', $before->id)
            ;
        }

        $rows = $query->execute()->fetchAssocAll();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function end(string $originalActivityUrl, int $remoteActorId, string $state, int $now): ?RemoteInteraction
    {
        if (!\in_array($state, ['undone', 'deleted', 'rejected'], true) || $remoteActorId < 1 || $now < 1) {
            throw new \InvalidArgumentException('The remote ActivityPub interaction terminal transition is invalid.');
        }

        $interaction = $this->findByActivityUrl($originalActivityUrl);
        if (!$interaction instanceof RemoteInteraction) {
            return null;
        }

        if ($interaction->remoteActorId !== $remoteActorId) {
            throw new \DomainException("An ActivityPub actor cannot end another actor's interaction.");
        }

        if ($interaction->state !== 'active') {
            return $interaction;
        }

        $this->dbLayer->update(ActivityPubSchema::INTERACTION_TABLE)
            ->set('state', ':state')->setParameter('state', $state)
            ->set('ended_at', ':ended_at')->setParameter('ended_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $interaction->id)
            ->andWhere('remote_actor_id = :remote_actor_id')->setParameter('remote_actor_id', $remoteActorId)
            ->andWhere('state = :active')->setParameter('active', 'active')
            ->execute()
        ;

        return $this->findByActivityUrl($originalActivityUrl)
            ?? throw new \RuntimeException('The ended remote ActivityPub interaction cannot be reloaded.');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): RemoteInteraction
    {
        try {
            $provenance = json_decode((string)$row['provenance_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!\is_array($provenance) || array_is_list($provenance)) {
                throw new \JsonException('Expected an ActivityPub interaction provenance object.');
            }

            return new RemoteInteraction(
                (int)$row['id'],
                (string)$row['interaction_type'],
                (int)$row['remote_actor_id'],
                (string)$row['remote_activity_url'],
                $row['remote_object_url'] === null ? null : (string)$row['remote_object_url'],
                $row['local_object_id'] === null ? null : (int)$row['local_object_id'],
                $row['local_comment_id'] === null ? null : (int)$row['local_comment_id'],
                (string)$row['reaction_source_key'],
                (string)$row['emoji'],
                (bool)$row['is_public'],
                (string)$row['state'],
                $provenance,
                (int)$row['created_at'],
                (int)$row['updated_at'],
                $row['ended_at'] === null ? null : (int)$row['ended_at'],
                $row['local_note_id'] === null ? null : (int)$row['local_note_id'],
            );
        } catch (\JsonException | \InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored remote ActivityPub interaction is invalid.', 0, $exception);
        }
    }

    private function assertEquivalent(RemoteInteraction $stored, NewRemoteInteraction $expected): void
    {
        if ($stored->type !== $expected->type
            || $stored->remoteActorId !== $expected->remoteActorId
            || $stored->remoteObjectUrl !== $expected->remoteObjectUrl
            || $stored->localObjectId !== $expected->localObjectId
            || $stored->localNoteId !== $expected->localNoteId
        ) {
            throw new \DomainException('A conflicting remote ActivityPub activity id was already applied.');
        }
    }
}
