<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Crash-safe, idempotent, portable upgrades for already-installed federation storage. */
final class ActivityPubSchemaMigrator
{
    public static function migrate(DbLayer $dbLayer): void
    {
        $version = self::storedVersion($dbLayer);
        if ($version > ActivityPubSchema::PROFILE_VERSION) {
            throw new \RuntimeException('The ActivityPub database profile is newer than this code.');
        }

        if ($version < 1) {
            throw new \RuntimeException('The ActivityPub database profile version is invalid.');
        }

        while ($version < ActivityPubSchema::PROFILE_VERSION) {
            $next = $version + 1;
            match ($next) {
                2       => self::migrateToVersion2($dbLayer),
                3       => self::migrateToVersion3($dbLayer),
                4       => self::migrateToVersion4($dbLayer),
                5       => self::migrateToVersion5($dbLayer),
                6       => self::migrateToVersion6($dbLayer),
                7       => self::migrateToVersion7($dbLayer),
                8       => self::migrateToVersion8($dbLayer),
                9       => self::migrateToVersion9($dbLayer),
                10      => self::migrateToVersion10($dbLayer),
                11      => self::migrateToVersion11($dbLayer),
                default => throw new \LogicException('No ActivityPub database migration is defined for profile ' . $next . '.'),
            };
            $affected = $dbLayer->update(ActivityPubSchema::STATE_TABLE)
                ->set('profile_version', ':next')->setParameter('next', $next)
                ->set('updated_at', ':updated_at')->setParameter('updated_at', time())
                ->where('id = :id')->setParameter('id', 'installation')
                ->andWhere('profile_version = :current')->setParameter('current', $version)
                ->execute()
                ->affectedRows()
            ;
            if ($affected !== 1 && self::storedVersion($dbLayer) < $next) {
                throw new \RuntimeException('The ActivityPub database profile changed concurrently during migration.');
            }

            $version = self::storedVersion($dbLayer);
        }

        self::assertCurrentShape($dbLayer);
    }

    private static function migrateToVersion2(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            ActivityPubSchema::ACTOR_TABLE,
            'moved_to_url',
            SchemaBuilderInterface::TYPE_LONGTEXT,
            null,
            true,
        );
        $dbLayer->addField(
            ActivityPubSchema::ACTOR_TABLE,
            'moved_at',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            null,
            true,
        );
        $dbLayer->addField(
            ActivityPubSchema::REMOTE_ACTOR_TABLE,
            'moved_to_url',
            SchemaBuilderInterface::TYPE_LONGTEXT,
            null,
            true,
        );
        $dbLayer->addField(
            ActivityPubSchema::REMOTE_ACTOR_TABLE,
            'moved_at',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            null,
            true,
        );
    }

    private static function migrateToVersion3(DbLayer $dbLayer): void
    {
        ActivityPubSchema::createActivationAttempts($dbLayer);
    }

    private static function migrateToVersion4(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            ActivityPubSchema::REMOTE_ACTOR_TABLE,
            'avatar_url',
            SchemaBuilderInterface::TYPE_LONGTEXT,
            null,
            true,
        );
        ActivityPubSchema::createRemoteMedia($dbLayer);
    }

    private static function migrateToVersion5(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            ActivityPubSchema::INTERACTION_TABLE,
            'local_note_id',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            null,
            true,
        );
        $dbLayer->addIndex(
            ActivityPubSchema::INTERACTION_TABLE,
            'public_note_reply_idx',
            ['local_note_id', 'interaction_type', 'is_public', 'created_at', 'id'],
        );
        $dbLayer->addForeignKey(
            ActivityPubSchema::INTERACTION_TABLE,
            'fk_local_note',
            ['local_note_id'],
            ActivityPubSchema::LOCAL_NOTE_TABLE,
            ['id'],
        );
    }

    private static function migrateToVersion6(DbLayer $dbLayer): void
    {
        ActivityPubSchema::createContentSettings($dbLayer);
    }

    private static function migrateToVersion7(DbLayer $dbLayer): void
    {
        ActivityPubSchema::createBackfills($dbLayer);
    }

    private static function migrateToVersion8(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            ActivityPubSchema::OBJECT_TABLE,
            'broadcast_at',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            null,
            true,
        );

        $rows = $dbLayer->select('object_id', 'MIN(created_at) AS broadcast_at')
            ->from(ActivityPubSchema::ACTIVITY_TABLE)
            ->where('object_id IS NOT NULL')
            ->andWhere('delivery_intent = :delivery_intent')->setParameter('delivery_intent', 'followers')
            ->groupBy('object_id')
            ->execute()
        ;
        while ($row = $rows->fetchAssoc()) {
            $objectId   = (int)$row['object_id'];
            $broadcastAt = (int)$row['broadcast_at'];
            if ($objectId < 1 || $broadcastAt < 1) {
                throw new \RuntimeException('An existing ActivityPub broadcast history row is invalid.');
            }

            $dbLayer->update(ActivityPubSchema::OBJECT_TABLE)
                ->set('broadcast_at', ':broadcast_at')->setParameter('broadcast_at', $broadcastAt)
                ->where('id = :id')->setParameter('id', $objectId)
                ->andWhere('broadcast_at IS NULL')
                ->execute()
            ;
        }
    }

    private static function migrateToVersion9(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            ActivityPubSchema::REMOTE_ACTOR_TABLE,
            'featured_url',
            SchemaBuilderInterface::TYPE_LONGTEXT,
            null,
            true,
        );
        $dbLayer->addField(
            ActivityPubSchema::REMOTE_OBJECT_TABLE,
            'featured_at',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            null,
            true,
        );
    }

    private static function migrateToVersion10(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            ActivityPubSchema::STATE_TABLE,
            'last_runner_at',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            null,
            true,
        );
        $dbLayer->addField(
            ActivityPubSchema::STATE_TABLE,
            'last_runner_code',
            SchemaBuilderInterface::TYPE_STRING,
            80,
            false,
            '',
        );
    }

    private static function migrateToVersion11(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            ActivityPubSchema::STATE_TABLE,
            'posts_enabled',
            SchemaBuilderInterface::TYPE_BOOLEAN,
            null,
            false,
            1,
        );
        $dbLayer->addField(
            ActivityPubSchema::STATE_TABLE,
            'default_visibility',
            SchemaBuilderInterface::TYPE_STRING,
            16,
            false,
            'public',
        );
    }

    private static function storedVersion(DbLayer $dbLayer): int
    {
        $value = $dbLayer->select('profile_version')
            ->from(ActivityPubSchema::STATE_TABLE)
            ->where('id = :id')->setParameter('id', 'installation')
            ->execute()
            ->result()
        ;
        if ($value === null || $value === false || !is_numeric($value)) {
            throw new \RuntimeException('The ActivityPub installation state is missing its database profile.');
        }

        return (int)$value;
    }

    private static function assertCurrentShape(DbLayer $dbLayer): void
    {
        foreach ([
            ActivityPubSchema::STATE_TABLE        => [
                'posts_enabled',
                'default_visibility',
                'last_runner_at',
                'last_runner_code',
            ],
            ActivityPubSchema::ACTOR_TABLE        => ['moved_to_url', 'moved_at'],
            ActivityPubSchema::REMOTE_ACTOR_TABLE => ['moved_to_url', 'moved_at', 'avatar_url', 'featured_url'],
            ActivityPubSchema::REMOTE_OBJECT_TABLE => ['featured_at'],
            ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE => [
                'actor_id',
                'canonical_origin',
                'base_path',
                'state',
                'next_step',
                'results_json',
                'signed_probe_received_at',
                'expires_at',
                'completed_at',
            ],
            ActivityPubSchema::REMOTE_MEDIA_TABLE => [
                'public_id',
                'remote_actor_id',
                'source_url_hash',
                'published_source_hash',
                'request_url',
                'state',
                'available_at',
                'storage_key',
                'content_hash',
                'refresh_at',
                'serve_until',
            ],
            ActivityPubSchema::INTERACTION_TABLE => ['local_note_id'],
            ActivityPubSchema::OBJECT_TABLE => ['broadcast_at'],
            ActivityPubSchema::CONTENT_SETTING_TABLE => [
                'local_type',
                'local_id',
                'publication_mode',
                'delivery_mode',
                'object_type',
                'visibility',
                'language',
                'summary',
                'created_at',
                'updated_at',
            ],
            ActivityPubSchema::BACKFILL_JOB_TABLE => [
                'id',
                'selection_mode',
                'state',
                'requested_by',
                'total_count',
                'processed_count',
                'projected_count',
                'skipped_count',
                'failed_count',
                'created_at',
                'started_at',
                'completed_at',
                'updated_at',
            ],
            ActivityPubSchema::BACKFILL_ITEM_TABLE => [
                'job_id',
                'sequence_number',
                'local_type',
                'local_id',
                'state',
                'result_action',
                'last_error',
                'created_at',
                'processed_at',
            ],
        ] as $table => $fields) {
            foreach ($fields as $field) {
                if (!$dbLayer->fieldExists($table, $field)) {
                    throw new \RuntimeException('The ActivityPub database profile is missing ' . $table . '.' . $field . '.');
                }
            }
        }
    }
}
