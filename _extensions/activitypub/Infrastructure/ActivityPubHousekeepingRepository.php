<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use S2\Cms\Pdo\DbLayer;
use s2_extensions\activitypub\Domain\DeliveryState;
use s2_extensions\activitypub\Domain\InboxState;

/** Small portable retention batches; immutable identities and tombstones are never pruned. */
final readonly class ActivityPubHousekeepingRepository
{
    private const int BATCH_SIZE = 100;

    private const int AUDIT_RETENTION_SECONDS = 90 * 24 * 60 * 60;

    private const int ACTIVATION_ATTEMPT_RETENTION_SECONDS = 7 * 24 * 60 * 60;

    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function redactExpiredInboxPayloads(int $now): int
    {
        $rows = $this->dbLayer->select('id')
            ->from(ActivityPubSchema::INBOX_TABLE)
            ->where('raw_expires_at <= :now')->setParameter('now', $now)
            ->andWhere('raw_body <> :empty')->setParameter('empty', '')
            ->andWhere('state IN (:processed, :ignored, :failed)')
            ->setParameter('processed', InboxState::PROCESSED->value)
            ->setParameter('ignored', InboxState::IGNORED->value)
            ->setParameter('failed', InboxState::FAILED->value)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->execute()
            ->fetchColumn()
        ;
        $ids = array_values(array_map(intval(...), $rows));
        if ($ids === []) {
            return 0;
        }

        [$condition, $parameters] = $this->integerInCondition('id', $ids);
        $query = $this->dbLayer->update(ActivityPubSchema::INBOX_TABLE)
            ->set('raw_body', ':empty_body')->setParameter('empty_body', '')
            ->set('transport_json', ':empty_transport')->setParameter('empty_transport', '{}')
            ->set('fetched_object_json', ':empty_object')->setParameter('empty_object', '')
            ->set('fetch_redirect_chain_json', ':empty_chain')->setParameter('empty_chain', '[]')
            ->where($condition)
        ;
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        return $query->execute()->affectedRows();
    }

    public function pruneDeliveryAttempts(int $now): int
    {
        return $this->deleteIntegerBatch(
            ActivityPubSchema::DELIVERY_ATTEMPT_TABLE,
            'compact_after <= :threshold',
            ['threshold' => $now],
        );
    }

    public function pruneDetachedRemoteSnapshots(int $now): int
    {
        $rows = $this->dbLayer->select('snapshot.id')
            ->from(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE . ' AS snapshot')
            ->where('snapshot.retain_until <= :threshold')->setParameter('threshold', $now)
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM ' . ActivityPubSchema::REMOTE_ACTOR_TABLE . ' AS actor
                WHERE actor.current_snapshot_id = snapshot.id
            )')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM ' . ActivityPubSchema::REMOTE_OBJECT_TABLE . ' AS object
                WHERE object.current_snapshot_id = snapshot.id
            )')
            ->orderBy('snapshot.id')
            ->limit(self::BATCH_SIZE)
            ->execute()
            ->fetchColumn()
        ;

        return $this->deleteIntegerIds(
            ActivityPubSchema::REMOTE_SNAPSHOT_TABLE,
            array_values(array_map(intval(...), $rows)),
        );
    }

    public function pruneRateLimits(int $now): int
    {
        $rows = $this->dbLayer->select('bucket_hash')
            ->from(ActivityPubSchema::RATE_LIMIT_TABLE)
            ->where('blocked_until <= :now')->setParameter('now', $now)
            ->andWhere('updated_at < :threshold')->setParameter('threshold', max(0, $now - 24 * 60 * 60))
            ->orderBy('updated_at', 'bucket_hash')
            ->limit(self::BATCH_SIZE)
            ->execute()
            ->fetchColumn()
        ;
        $hashes = [];
        foreach ($rows as $value) {
            if (\is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1) {
                $hashes[] = $value;
            }
        }

        if ($hashes === []) {
            return 0;
        }

        $parameters = [];
        $placeholders = [];
        foreach ($hashes as $index => $hash) {
            $name = 'hash_' . $index;
            $parameters[$name] = $hash;
            $placeholders[] = ':' . $name;
        }

        $query = $this->dbLayer->delete(ActivityPubSchema::RATE_LIMIT_TABLE)
            ->where('bucket_hash IN (' . implode(', ', $placeholders) . ')')
        ;
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        return $query->execute()->affectedRows();
    }

    public function pruneReadNotifications(int $now): int
    {
        return $this->deleteIntegerBatch(
            ActivityPubSchema::NOTIFICATION_TABLE,
            'state = :state AND read_at IS NOT NULL AND read_at < :threshold',
            [
                'state'     => 'read',
                'threshold' => max(0, $now - self::AUDIT_RETENTION_SECONDS),
            ],
        );
    }

    public function pruneTerminalDeliveries(int $now): int
    {
        return $this->deleteIntegerBatch(
            ActivityPubSchema::DELIVERY_TABLE,
            'state IN (:delivered, :failed, :cancelled) AND updated_at < :threshold',
            [
                'delivered' => DeliveryState::DELIVERED->value,
                'failed'    => DeliveryState::FAILED->value,
                'cancelled' => DeliveryState::CANCELLED->value,
                'threshold' => max(0, $now - self::AUDIT_RETENTION_SECONDS),
            ],
        );
    }

    public function pruneExpiredActivationAttempts(int $now): int
    {
        $rows = $this->dbLayer->select('id')
            ->from(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE)
            ->where('state IN (:checking, :ready, :failed, :superseded)')
            ->setParameter('checking', 'checking')
            ->setParameter('ready', 'ready')
            ->setParameter('failed', 'failed')
            ->setParameter('superseded', 'superseded')
            ->andWhere('expires_at < :threshold')->setParameter(
                'threshold',
                max(0, $now - self::ACTIVATION_ATTEMPT_RETENTION_SECONDS),
            )
            ->orderBy('expires_at', 'id')
            ->limit(self::BATCH_SIZE)
            ->execute()
            ->fetchColumn()
        ;
        $ids = [];
        foreach ($rows as $value) {
            if (\is_string($value) && preg_match('/^[A-Za-z0-9_-]{22}$/D', $value) === 1) {
                $ids[] = $value;
            }
        }

        if ($ids === []) {
            return 0;
        }

        $placeholders = [];
        $query = $this->dbLayer->delete(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE);
        foreach ($ids as $index => $id) {
            $name = 'attempt_' . $index;
            $placeholders[] = ':' . $name;
            $query->setParameter($name, $id);
        }

        return $query->where('id IN (' . implode(', ', $placeholders) . ')')->execute()->affectedRows();
    }

    /** @param array<string, int|string> $parameters */
    private function deleteIntegerBatch(string $table, string $condition, array $parameters): int
    {
        $select = $this->dbLayer->select('id')
            ->from($table)
            ->where($condition)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
        ;
        foreach ($parameters as $name => $value) {
            $select->setParameter($name, $value);
        }

        $ids = array_values(array_map(intval(...), $select->execute()->fetchColumn()));

        return $this->deleteIntegerIds($table, $ids);
    }

    /** @param list<int> $ids */
    private function deleteIntegerIds(string $table, array $ids): int
    {
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }

        [$condition, $parameters] = $this->integerInCondition('id', $ids);
        $query = $this->dbLayer->delete($table)->where($condition);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        return $query->execute()->affectedRows();
    }

    /**
     * @param non-empty-list<int> $ids
     * @return array{string, array<string, int>}
     */
    private function integerInCondition(string $column, array $ids): array
    {
        $parameters = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $name = 'id_' . $index;
            $parameters[$name] = $id;
            $placeholders[] = ':' . $name;
        }

        return [$column . ' IN (' . implode(', ', $placeholders) . ')', $parameters];
    }
}
