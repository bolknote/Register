<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\LocalInteraction;

final readonly class LocalInteractionRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function find(int $localActorId, string $remoteObjectUrl, string $type, string $emoji): ?LocalInteraction
    {
        if ($localActorId < 1
            || !str_starts_with($remoteObjectUrl, 'https://')
            || !\in_array($type, ['like', 'emoji_react', 'announce'], true)
            || mb_strlen($emoji) > 64
        ) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::LOCAL_INTERACTION_TABLE)
            ->where('local_actor_id = :local_actor_id')->setParameter('local_actor_id', $localActorId)
            ->andWhere('remote_object_hash = :remote_object_hash')
            ->setParameter('remote_object_hash', hash('sha256', $remoteObjectUrl))
            ->andWhere('interaction_type = :interaction_type')->setParameter('interaction_type', $type)
            ->andWhere('emoji_hash = :emoji_hash')->setParameter('emoji_hash', hash('sha256', $emoji))
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            return null;
        }

        if (!hash_equals((string)$row['remote_object_url'], $remoteObjectUrl)
            || !hash_equals((string)$row['emoji'], $emoji)
        ) {
            throw new \RuntimeException('A local ActivityPub interaction SHA-256 collision was detected.');
        }

        return $this->hydrate($row);
    }

    public function create(NewLocalInteraction $interaction): LocalInteraction
    {
        $existing = $this->find(
            $interaction->localActorId,
            $interaction->remoteObjectUrl,
            $interaction->type,
            $interaction->emoji,
        );
        if ($existing instanceof LocalInteraction && $existing->state === 'active') {
            return $existing;
        }

        if (!$existing instanceof \Register\Extension\activitypub\Domain\LocalInteraction) {
            $this->dbLayer->insert(ActivityPubSchema::LOCAL_INTERACTION_TABLE)
                ->values([
                    'local_actor_id'     => ':local_actor_id',
                    'remote_actor_id'    => ':remote_actor_id',
                    'remote_object_hash' => ':remote_object_hash',
                    'remote_object_url'  => ':remote_object_url',
                    'interaction_type'   => ':interaction_type',
                    'emoji'              => ':emoji',
                    'emoji_hash'         => ':emoji_hash',
                    'state'              => ':state',
                    'local_activity_id'  => ':local_activity_id',
                    'created_at'         => ':created_at',
                    'updated_at'         => ':updated_at',
                ])
                ->execute([
                    'local_actor_id'      => $interaction->localActorId,
                    'remote_actor_id'     => $interaction->remoteActorId,
                    'remote_object_hash'  => hash('sha256', $interaction->remoteObjectUrl),
                    'remote_object_url'   => $interaction->remoteObjectUrl,
                    'interaction_type'    => $interaction->type,
                    'emoji'               => $interaction->emoji,
                    'emoji_hash'          => hash('sha256', $interaction->emoji),
                    'state'               => 'active',
                    'local_activity_id'   => $interaction->localActivityId,
                    'created_at'          => $interaction->createdAt,
                    'updated_at'          => $interaction->createdAt,
                ])
            ;
        } else {
            $this->dbLayer->update(ActivityPubSchema::LOCAL_INTERACTION_TABLE)
                ->set('remote_actor_id', ':remote_actor_id')->setParameter('remote_actor_id', $interaction->remoteActorId)
                ->set('state', ':state')->setParameter('state', 'active')
                ->set('local_activity_id', ':local_activity_id')->setParameter('local_activity_id', $interaction->localActivityId)
                ->set('undo_activity_id', 'NULL')
                ->set('ended_at', 'NULL')
                ->set('updated_at', ':updated_at')->setParameter('updated_at', $interaction->createdAt)
                ->where('id = :id')->setParameter('id', $existing->id)
                ->andWhere('state = :previous_state')->setParameter('previous_state', 'ended')
                ->execute()
            ;
        }

        return $this->find(
            $interaction->localActorId,
            $interaction->remoteObjectUrl,
            $interaction->type,
            $interaction->emoji,
        ) ?? throw new \RuntimeException('The local ActivityPub interaction cannot be reloaded.');
    }

    public function end(LocalInteraction $interaction, int $undoActivityId, int $now): LocalInteraction
    {
        if ($undoActivityId < 1 || $now < 1) {
            throw new \InvalidArgumentException('A local ActivityPub interaction Undo is invalid.');
        }

        if ($interaction->state !== 'active') {
            return $interaction;
        }

        $affected = $this->dbLayer->update(ActivityPubSchema::LOCAL_INTERACTION_TABLE)
            ->set('state', ':state')->setParameter('state', 'ended')
            ->set('undo_activity_id', ':undo_activity_id')->setParameter('undo_activity_id', $undoActivityId)
            ->set('ended_at', ':ended_at')->setParameter('ended_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $interaction->id)
            ->andWhere('state = :active')->setParameter('active', 'active')
            ->execute()
            ->affectedRows()
        ;
        if ($affected !== 1) {
            throw new \RuntimeException('The local ActivityPub interaction changed concurrently during Undo.');
        }

        return $this->find(
            $interaction->localActorId,
            $interaction->remoteObjectUrl,
            $interaction->type,
            $interaction->emoji,
        ) ?? throw new \RuntimeException('The ended local ActivityPub interaction cannot be reloaded.');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): LocalInteraction
    {
        try {
            return new LocalInteraction(
                (int)$row['id'],
                (int)$row['local_actor_id'],
                (int)$row['remote_actor_id'],
                (string)$row['remote_object_url'],
                (string)$row['interaction_type'],
                (string)$row['emoji'],
                (string)$row['state'],
                (int)$row['local_activity_id'],
                $row['undo_activity_id'] === null ? null : (int)$row['undo_activity_id'],
                (int)$row['created_at'],
                (int)$row['updated_at'],
                $row['ended_at'] === null ? null : (int)$row['ended_at'],
            );
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored local ActivityPub interaction is invalid.', 0, $exception);
        }
    }
}
