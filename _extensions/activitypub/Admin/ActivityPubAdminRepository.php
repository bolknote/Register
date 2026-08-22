<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Admin;

use S2\Cms\Pdo\DbLayer;
use s2_extensions\activitypub\Infrastructure\ActivityPubSchema;

final readonly class ActivityPubAdminRepository
{
    public function __construct(
        private DbLayer $dbLayer,
        private ?\PDO   $pdo = null,
    ) {
    }

    /**
     * @return array{
     *     inbox: int,
     *     inbox_failed: int,
     *     deliveries_ready: int,
     *     deliveries_delayed: int,
     *     deliveries_failed: int,
     *     followers: int,
     *     following: int,
     *     remote_objects: int,
     *     direct_notes: int,
     *     notifications: int,
     *     avatars_ready: int,
     *     avatars_pending: int,
     *     avatars_failed: int,
     *     moderation_pending: int,
     *     moderation_flags: int,
     *     moderation_rules: int,
     *     remote_cache_bytes: int
     * }
     */
    public function summary(): array
    {
        return [
            'inbox'              => $this->count(ActivityPubSchema::INBOX_TABLE),
            'inbox_failed'       => $this->countWhere(ActivityPubSchema::INBOX_TABLE, 'state', 'failed'),
            'deliveries_ready'   => $this->countWhere(ActivityPubSchema::DELIVERY_TABLE, 'state', 'pending'),
            'deliveries_delayed' => $this->countWhere(ActivityPubSchema::DELIVERY_TABLE, 'state', 'delayed'),
            'deliveries_failed'  => $this->countWhere(ActivityPubSchema::DELIVERY_TABLE, 'state', 'failed'),
            'followers'          => $this->followCount('incoming'),
            'following'          => $this->followCount('outgoing'),
            'remote_objects'     => $this->countWhere(ActivityPubSchema::REMOTE_OBJECT_TABLE, 'state', 'live'),
            'direct_notes'       => $this->countWhere(ActivityPubSchema::REMOTE_OBJECT_TABLE, 'visibility', 'direct'),
            'notifications'      => $this->countWhere(ActivityPubSchema::NOTIFICATION_TABLE, 'state', 'unread'),
            'avatars_ready'      => $this->countWhere(ActivityPubSchema::REMOTE_MEDIA_TABLE, 'state', 'ready'),
            'avatars_pending'    => $this->countWhere(ActivityPubSchema::REMOTE_MEDIA_TABLE, 'state', 'pending')
                + $this->countWhere(ActivityPubSchema::REMOTE_MEDIA_TABLE, 'state', 'processing'),
            'avatars_failed'     => $this->countWhere(ActivityPubSchema::REMOTE_MEDIA_TABLE, 'state', 'failed'),
            'moderation_pending' => $this->pendingModerationCount(),
            'moderation_flags'   => $this->activeFlagCount(),
            'moderation_rules'   => $this->enabledModerationRuleCount(),
            'remote_cache_bytes' => $this->remoteCacheBytes(),
        ];
    }

    /**
     * @return list<array{
     *     relationship_id: int,
     *     local_actor_id: int,
     *     remote_actor_id: int,
     *     state: string,
     *     actor_url: string,
     *     preferred_username: string,
     *     display_name: string,
     *     inbox_url: string,
     *     updated_at: int
     * }>
     */
    public function outgoingFollows(?int $localActorId = null): array
    {
        if ($localActorId !== null && $localActorId < 1) {
            throw new \InvalidArgumentException('A local ActivityPub actor identifier must be positive.');
        }

        $query = $this->dbLayer->select(
            'follow.id AS relationship_id',
            'follow.local_actor_id',
            'follow.remote_actor_id',
            'follow.state',
            'remote.actor_url',
            'remote.preferred_username',
            'remote.display_name',
            'remote.inbox_url',
            'follow.updated_at',
        )
            ->from(ActivityPubSchema::FOLLOW_TABLE . ' AS follow')
            ->innerJoin(
                ActivityPubSchema::REMOTE_ACTOR_TABLE . ' AS remote',
                'remote.id = follow.remote_actor_id',
            )
            ->where('follow.direction = :direction')->setParameter('direction', 'outgoing')
            ->andWhere('follow.state IN (:pending, :accepted)')
            ->setParameter('pending', 'pending')
            ->setParameter('accepted', 'accepted')
            ->orderBy('follow.updated_at DESC, follow.id DESC')
        ;
        if ($localActorId !== null) {
            $query->andWhere('follow.local_actor_id = :local_actor_id')->setParameter('local_actor_id', $localActorId);
        }

        $rows = $query->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map(static fn(array $row): array => [
            'relationship_id'   => (int)$row['relationship_id'],
            'local_actor_id'    => (int)$row['local_actor_id'],
            'remote_actor_id'   => (int)$row['remote_actor_id'],
            'state'             => (string)$row['state'],
            'actor_url'         => (string)$row['actor_url'],
            'preferred_username' => (string)$row['preferred_username'],
            'display_name'      => (string)$row['display_name'],
            'inbox_url'         => (string)$row['inbox_url'],
            'updated_at'        => (int)$row['updated_at'],
        ], $rows));
    }

    /**
     * @return list<array{direction: string, origin: string, failure_count: int, last_failure_at: int}>
     */
    public function failuresByDomain(): array
    {
        $groups = [];
        foreach ([
            [ActivityPubSchema::DELIVERY_TABLE, 'outbound', 'updated_at'],
            [ActivityPubSchema::INBOX_TABLE, 'inbound', 'received_at'],
        ] as [$table, $direction, $timestampColumn]) {
            $rows = $this->dbLayer->select(
                'effective_origin',
                'COUNT(*) AS failure_count',
                'MAX(' . $timestampColumn . ') AS last_failure_at',
            )
                ->from($table)
                ->where('state = :state')->setParameter('state', 'failed')
                ->groupBy('effective_origin')
                ->execute()
                ->fetchAssocAll()
            ;
            foreach ($rows as $row) {
                $groups[] = [
                    'direction'       => $direction,
                    'origin'          => (string)$row['effective_origin'],
                    'failure_count'   => (int)$row['failure_count'],
                    'last_failure_at' => (int)$row['last_failure_at'],
                ];
            }
        }

        usort($groups, static function (array $left, array $right): int {
            $byCount = $right['failure_count'] <=> $left['failure_count'];
            if ($byCount !== 0) {
                return $byCount;
            }

            $byTime = $right['last_failure_at'] <=> $left['last_failure_at'];

            return $byTime !== 0 ? $byTime : strcmp($left['origin'], $right['origin']);
        });

        return array_slice($groups, 0, 20);
    }

    /**
     * @return list<array{
     *     id: int,
     *     scope: string,
     *     match_value: string,
     *     action: string,
     *     priority: int,
     *     updated_at: int
     * }>
     */
    public function moderationRules(): array
    {
        $rows = $this->dbLayer->select('id', 'scope', 'match_value', 'action', 'priority', 'updated_at')
            ->from(ActivityPubSchema::MODERATION_RULE_TABLE)
            ->where('enabled = 1')
            ->orderBy('priority DESC', 'updated_at DESC', 'id DESC')
            ->limit(50)
            ->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map(static fn(array $row): array => [
            'id'          => (int)$row['id'],
            'scope'       => (string)$row['scope'],
            'match_value' => (string)$row['match_value'],
            'action'      => (string)$row['action'],
            'priority'    => (int)$row['priority'],
            'updated_at'  => (int)$row['updated_at'],
        ], $rows));
    }

    private function count(string $table): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')->from($table)->execute()->result();
    }

    private function countWhere(string $table, string $column, string $value): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from($table)
            ->where($column . ' = :value')->setParameter('value', $value)
            ->execute()
            ->result()
        ;
    }

    private function followCount(string $direction): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::FOLLOW_TABLE)
            ->where('direction = :direction')->setParameter('direction', $direction)
            ->andWhere('state = :state')->setParameter('state', 'accepted')
            ->execute()
            ->result()
        ;
    }

    private function pendingModerationCount(): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::INTERACTION_TABLE)
            ->where('interaction_type = :type')->setParameter('type', 'reply')
            ->andWhere('state = :state')->setParameter('state', 'active')
            ->andWhere('is_public = 0')
            ->andWhere('local_comment_id IS NOT NULL')
            ->execute()
            ->result()
        ;
    }

    private function activeFlagCount(): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::INTERACTION_TABLE)
            ->where('interaction_type = :type')->setParameter('type', 'flag')
            ->andWhere('state = :state')->setParameter('state', 'active')
            ->execute()
            ->result()
        ;
    }

    private function enabledModerationRuleCount(): int
    {
        return (int)$this->dbLayer->select('COUNT(*)')
            ->from(ActivityPubSchema::MODERATION_RULE_TABLE)
            ->where('enabled = 1')
            ->execute()
            ->result()
        ;
    }

    private function remoteCacheBytes(): int
    {
        $driver = $this->pdo?->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver !== null && !\is_string($driver)) {
            throw new \RuntimeException('PDO returned an invalid driver name.');
        }

        $snapshotLength = match ($driver) {
            'mysql', 'pgsql' => 'OCTET_LENGTH(document_json)',
            'sqlite'         => 'LENGTH(CAST(document_json AS BLOB))',
            null             => 'LENGTH(document_json)',
            default          => throw new \RuntimeException('Unsupported database driver for ActivityPub diagnostics.'),
        };
        $snapshots = (int)$this->dbLayer->select('COALESCE(SUM(' . $snapshotLength . '), 0)')
            ->from(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE)
            ->execute()
            ->result()
        ;
        $media = (int)$this->dbLayer->select('COALESCE(SUM(byte_size), 0)')
            ->from(ActivityPubSchema::REMOTE_MEDIA_TABLE)
            ->where('state = :state')->setParameter('state', 'ready')
            ->execute()
            ->result()
        ;

        return max(0, $snapshots) + max(0, $media);
    }
}
