<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;

/** Portable, module-owned federation storage. No table depends on an unstable product table. */
final class ActivityPubSchema
{
    public const int PROFILE_VERSION = 11;

    public const string STATE_TABLE = 'register_ap_state';

    public const string ACTOR_TABLE = 'register_ap_actor';

    public const string ACTOR_HANDLE_TABLE = 'register_ap_actor_handle';

    public const string ACTOR_KEY_TABLE = 'register_ap_actor_key';

    public const string ACTIVATION_ATTEMPT_TABLE = 'register_ap_activation_attempt';

    public const string CONTENT_SETTING_TABLE = 'register_ap_content_setting';

    public const string BACKFILL_JOB_TABLE = 'register_ap_backfill_job';

    public const string BACKFILL_ITEM_TABLE = 'register_ap_backfill_item';

    public const string OBJECT_TABLE = 'register_ap_object';

    public const string LOCAL_NOTE_TABLE = 'register_ap_local_note';

    public const string ACTIVITY_TABLE = 'register_ap_activity';

    public const string INBOX_TABLE = 'register_ap_inbox';

    public const string REMOTE_ACTOR_TABLE = 'register_ap_remote_actor';

    public const string REMOTE_OBJECT_TABLE = 'register_ap_remote_object';

    public const string REMOTE_SNAPSHOT_TABLE = 'register_ap_remote_snapshot';

    public const string REMOTE_RECIPIENT_TABLE = 'register_ap_remote_recipient';

    public const string REMOTE_MEDIA_TABLE = 'register_ap_remote_media';

    public const string FOLLOW_TABLE = 'register_ap_follow';

    public const string DELIVERY_TABLE = 'register_ap_delivery';

    public const string DELIVERY_ATTEMPT_TABLE = 'register_ap_delivery_attempt';

    public const string INTERACTION_TABLE = 'register_ap_interaction';

    public const string LOCAL_INTERACTION_TABLE = 'register_ap_local_interaction';

    public const string MODERATION_RULE_TABLE = 'register_ap_moderation_rule';

    public const string NOTIFICATION_TABLE = 'register_ap_notification';

    public const string RATE_LIMIT_TABLE = 'register_ap_rate_limit';

    /** @return list<string> */
    public static function tables(): array
    {
        return [
            self::STATE_TABLE,
            self::ACTOR_TABLE,
            self::ACTOR_HANDLE_TABLE,
            self::ACTOR_KEY_TABLE,
            self::ACTIVATION_ATTEMPT_TABLE,
            self::CONTENT_SETTING_TABLE,
            self::BACKFILL_JOB_TABLE,
            self::BACKFILL_ITEM_TABLE,
            self::REMOTE_ACTOR_TABLE,
            self::REMOTE_OBJECT_TABLE,
            self::REMOTE_SNAPSHOT_TABLE,
            self::REMOTE_RECIPIENT_TABLE,
            self::REMOTE_MEDIA_TABLE,
            self::OBJECT_TABLE,
            self::LOCAL_NOTE_TABLE,
            self::ACTIVITY_TABLE,
            self::INBOX_TABLE,
            self::FOLLOW_TABLE,
            self::DELIVERY_TABLE,
            self::DELIVERY_ATTEMPT_TABLE,
            self::INTERACTION_TABLE,
            self::LOCAL_INTERACTION_TABLE,
            self::MODERATION_RULE_TABLE,
            self::NOTIFICATION_TABLE,
            self::RATE_LIMIT_TABLE,
        ];
    }

    public static function install(DbLayer $dbLayer): void
    {
        self::createState($dbLayer);
        self::createActors($dbLayer);
        self::createActivationAttempts($dbLayer);
        self::createContentSettings($dbLayer);
        self::createBackfills($dbLayer);
        self::createRemoteCache($dbLayer);
        self::createLocalObjectsAndActivities($dbLayer);
        self::createInbox($dbLayer);
        self::createFollows($dbLayer);
        self::createDelivery($dbLayer);
        self::createInteractions($dbLayer);
        self::createModerationAndOperations($dbLayer);
        self::seedState($dbLayer);
        ActivityPubSchemaMigrator::migrate($dbLayer);
    }

    private static function createState(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::STATE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('id', 16)
                ->addInteger('profile_version', true, default: self::PROFILE_VERSION)
                ->addString('lifecycle_state', 16, default: 'installed')
                ->addString('canonical_origin', 255, true, null)
                ->addString('base_path', 255)
                ->addString('site_actor_type', 16, default: 'Service')
                ->addString('post_object_type', 16, default: 'Article')
                ->addString('content_mode', 16, default: 'full')
                ->addBoolean('posts_enabled', default: true)
                ->addBoolean('pages_enabled')
                ->addString('default_visibility', 16, default: 'public')
                ->addBoolean('auto_accept_follows', default: true)
                ->addInteger('created_at', true)
                ->addInteger('activated_at', true, true, null)
                ->addInteger('paused_at', true, true, null)
                ->addInteger('decommissioned_at', true, true, null)
                ->addInteger('last_runner_at', true, true, null)
                ->addString('last_runner_code', 80)
                ->addInteger('updated_at', true)
                ->setPrimaryKey(['id'])
            ;
        });
    }

    private static function createActors(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::ACTOR_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('public_id', 22)
                ->addString('actor_kind', 16)
                ->addInteger('site_slot', true, true, null)
                ->addInteger('author_id', true, true, null)
                ->addString('actor_type', 16)
                ->addString('display_name', 255)
                ->addLongText('summary_html', nullable: false)
                ->addLongText('profile_url', nullable: false)
                ->addLongText('avatar_data')
                ->addLongText('header_data')
                ->addLongText('metadata_json', nullable: false)
                ->addString('state', 16, default: 'draft')
                ->addLongText('moved_to_url')
                ->addInteger('moved_at', true, true, null)
                ->addBoolean('discoverable', default: true)
                ->addInteger('created_at', true)
                ->addInteger('activated_at', true, true, null)
                ->addInteger('deactivated_at', true, true, null)
                ->addInteger('updated_at', true)
                ->addUniqueIndex('public_id_idx', ['public_id'])
                ->addUniqueIndex('site_slot_idx', ['site_slot'])
                ->addUniqueIndex('author_idx', ['author_id'])
                ->addIndex('author_state_idx', ['author_id', 'state'])
                ->addIndex('kind_state_idx', ['actor_kind', 'state'])
            ;
        });

        $dbLayer->createTable(self::ACTOR_HANDLE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('handle', 32)
                ->addInteger('actor_id', true)
                ->addBoolean('is_current')
                ->addInteger('created_at', true)
                ->addInteger('retired_at', true, true, null)
                ->setPrimaryKey(['handle'])
                ->addIndex('actor_current_idx', ['actor_id', 'is_current'])
                ->addForeignKey('fk_actor', ['actor_id'], self::ACTOR_TABLE, ['id'], 'CASCADE')
            ;
        });

        $dbLayer->createTable(self::ACTOR_KEY_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addInteger('actor_id', true)
                ->addString('public_id', 22)
                ->addString('algorithm', 32, default: 'rsa-sha256')
                ->addLongText('public_key_pem', nullable: false)
                ->addLongText('private_key_ciphertext', nullable: false)
                ->addString('private_key_nonce', 64)
                ->addBoolean('is_current', default: true)
                ->addInteger('created_at', true)
                ->addInteger('retired_at', true, true, null)
                ->addInteger('destroyed_at', true, true, null)
                ->addUniqueIndex('public_id_idx', ['public_id'])
                ->addIndex('actor_current_idx', ['actor_id', 'is_current'])
                ->addForeignKey('fk_actor', ['actor_id'], self::ACTOR_TABLE, ['id'], 'CASCADE')
            ;
        });
    }

    /** Kept public so the versioned migrator can idempotently install this table. */
    public static function createActivationAttempts(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::ACTIVATION_ATTEMPT_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('id', 22)
                ->addInteger('actor_id', true)
                ->addString('canonical_origin', 255)
                ->addString('base_path', 255)
                ->addString('state', 16, default: 'checking')
                ->addInteger('next_step', true)
                ->addLongText('results_json', nullable: false)
                ->addInteger('signed_probe_received_at', true, true, null)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addInteger('expires_at', true)
                ->addInteger('completed_at', true, true, null)
                ->setPrimaryKey(['id'])
                ->addIndex('actor_created_idx', ['actor_id', 'created_at'])
                ->addIndex('state_expiry_idx', ['state', 'expires_at'])
                ->addForeignKey('fk_actor', ['actor_id'], self::ACTOR_TABLE, ['id'], 'CASCADE')
            ;
        });
    }

    /** Kept public so the versioned migrator can idempotently install this table. */
    public static function createContentSettings(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::CONTENT_SETTING_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('local_type', 16)
                ->addInteger('local_id', true)
                ->addString('publication_mode', 16, default: 'inherit')
                ->addString('delivery_mode', 16, true, null)
                ->addString('object_type', 16, true, null)
                ->addString('visibility', 16, true, null)
                ->addString('language', 35, true, null)
                ->addLongText('summary')
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->setPrimaryKey(['local_type', 'local_id'])
                ->addIndex('publication_idx', ['publication_mode', 'local_type', 'local_id'])
            ;
        });
    }

    /** Kept public so the versioned migrator can idempotently install these tables. */
    public static function createBackfills(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::BACKFILL_JOB_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('id', 22)
                ->addString('selection_mode', 16)
                ->addString('state', 24, default: 'pending')
                ->addInteger('requested_by', true)
                ->addInteger('total_count', true)
                ->addInteger('processed_count', true)
                ->addInteger('projected_count', true)
                ->addInteger('skipped_count', true)
                ->addInteger('failed_count', true)
                ->addInteger('created_at', true)
                ->addInteger('started_at', true, true, null)
                ->addInteger('completed_at', true, true, null)
                ->addInteger('updated_at', true)
                ->setPrimaryKey(['id'])
                ->addIndex('state_created_idx', ['state', 'created_at'])
            ;
        });

        $dbLayer->createTable(self::BACKFILL_ITEM_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('job_id', 22)
                ->addInteger('sequence_number', true)
                ->addString('local_type', 16)
                ->addInteger('local_id', true)
                ->addString('state', 16, default: 'pending')
                ->addString('result_action', 16)
                ->addLongText('last_error')
                ->addInteger('created_at', true)
                ->addInteger('processed_at', true, true, null)
                ->setPrimaryKey(['job_id', 'sequence_number'])
                ->addUniqueIndex('job_content_idx', ['job_id', 'local_type', 'local_id'])
                ->addIndex('job_state_idx', ['job_id', 'state', 'sequence_number'])
                ->addForeignKey('fk_backfill_job', ['job_id'], self::BACKFILL_JOB_TABLE, ['id'], 'CASCADE')
            ;
        });
    }

    private static function createLocalObjectsAndActivities(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::OBJECT_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('public_id', 22)
                ->addString('local_type', 16)
                ->addInteger('local_id', true)
                ->addInteger('incarnation', true, default: 1)
                ->addInteger('owner_actor_id', true)
                ->addString('object_type', 16)
                ->addString('visibility', 16)
                ->addString('state', 16, default: 'live')
                ->addLongText('canonical_url', nullable: false)
                ->addString('canonical_url_hash', 64)
                ->addLongText('snapshot_json', nullable: false)
                ->addString('snapshot_hash', 64)
                ->addInteger('published_at', true)
                ->addInteger('updated_at', true)
                ->addInteger('deleted_at', true, true, null)
                ->addInteger('featured_at', true, true, null)
                ->addInteger('broadcast_at', true, true, null)
                ->addInteger('created_at', true)
                ->addUniqueIndex('public_id_idx', ['public_id'])
                ->addUniqueIndex('local_incarnation_idx', ['local_type', 'local_id', 'incarnation'])
                ->addIndex('local_state_idx', ['local_type', 'local_id', 'state'])
                ->addIndex('owner_state_idx', ['owner_actor_id', 'state', 'published_at'])
                ->addIndex('owner_featured_idx', ['owner_actor_id', 'featured_at', 'id'])
                ->addIndex('canonical_hash_idx', ['canonical_url_hash'])
                ->addForeignKey('fk_owner_actor', ['owner_actor_id'], self::ACTOR_TABLE, ['id'])
            ;
        });

        $dbLayer->createTable(self::LOCAL_NOTE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('public_id', 22)
                ->addInteger('actor_id', true)
                ->addString('in_reply_to_hash', 64)
                ->addLongText('in_reply_to_url', nullable: false)
                ->addInteger('remote_actor_id', true)
                ->addString('visibility', 16)
                ->addString('state', 16, default: 'live')
                ->addLongText('snapshot_json', nullable: false)
                ->addString('snapshot_hash', 64)
                ->addInteger('published_at', true)
                ->addInteger('updated_at', true)
                ->addInteger('deleted_at', true, true, null)
                ->addInteger('created_at', true)
                ->addUniqueIndex('public_id_idx', ['public_id'])
                ->addIndex('actor_state_idx', ['actor_id', 'state', 'published_at', 'id'])
                ->addIndex('reply_target_idx', ['in_reply_to_hash', 'state'])
                ->addForeignKey('fk_actor', ['actor_id'], self::ACTOR_TABLE, ['id'])
                ->addForeignKey('fk_remote_actor', ['remote_actor_id'], self::REMOTE_ACTOR_TABLE, ['id'])
            ;
        });

        $dbLayer->createTable(self::ACTIVITY_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('public_id', 22)
                ->addInteger('actor_id', true)
                ->addInteger('object_id', true, true, null)
                ->addInteger('local_note_id', true, true, null)
                ->addString('activity_type', 16)
                ->addString('visibility', 16)
                ->addString('delivery_intent', 16, default: 'followers')
                ->addString('deduplication_key', 128)
                ->addLongText('serialized_body', nullable: false)
                ->addString('body_hash', 64)
                ->addInteger('published_at', true)
                ->addInteger('created_at', true)
                ->addUniqueIndex('public_id_idx', ['public_id'])
                ->addUniqueIndex('deduplication_idx', ['deduplication_key'])
                ->addIndex('actor_published_idx', ['actor_id', 'published_at', 'id'])
                ->addIndex('object_published_idx', ['object_id', 'published_at'])
                ->addForeignKey('fk_actor', ['actor_id'], self::ACTOR_TABLE, ['id'])
                ->addForeignKey('fk_object', ['object_id'], self::OBJECT_TABLE, ['id'])
                ->addForeignKey('fk_local_note', ['local_note_id'], self::LOCAL_NOTE_TABLE, ['id'])
            ;
        });
    }

    private static function createInbox(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::INBOX_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('deduplication_hash', 64)
                ->addInteger('target_local_actor_id', true, true, null)
                ->addString('activity_type', 32)
                ->addLongText('activity_url')
                ->addString('activity_url_hash', 64, true, null)
                ->addString('body_hash', 64)
                ->addLongText('actor_url')
                ->addString('actor_url_hash', 64, true, null)
                ->addLongText('key_id', nullable: false)
                ->addString('signature_type', 16)
                ->addString('effective_origin', 255)
                ->addLongText('raw_body', nullable: false)
                ->addLongText('transport_json', nullable: false)
                ->addString('state', 16, default: 'received')
                ->addString('claim_token', 32, true, null)
                ->addInteger('claimed_at', true, true, null)
                ->addInteger('attempt_count', true)
                ->addInteger('key_refresh_count', true)
                ->addBoolean('force_key_refresh')
                ->addString('fetch_kind', 16, default: 'actor')
                ->addBoolean('fetch_signed')
                ->addLongText('fetch_url', nullable: false)
                ->addInteger('fetch_redirect_count', true)
                ->addLongText('fetch_redirect_chain_json', nullable: false)
                ->addLongText('fetched_object_json', nullable: false)
                ->addString('fetched_object_hash', 64)
                ->addInteger('available_at', true)
                ->addInteger('received_at', true)
                ->addInteger('processed_at', true, true, null)
                ->addInteger('raw_expires_at', true)
                ->addString('error_code', 64)
                ->addLongText('result_detail')
                ->addUniqueIndex('deduplication_idx', ['deduplication_hash'])
                ->addIndex('runnable_idx', ['state', 'available_at', 'id'])
                ->addIndex('activity_url_idx', ['activity_url_hash'])
                ->addIndex('actor_received_idx', ['actor_url_hash', 'received_at'])
                ->addIndex('raw_expiry_idx', ['raw_expires_at'])
                ->addForeignKey('fk_target_actor', ['target_local_actor_id'], self::ACTOR_TABLE, ['id'])
            ;
        });
    }

    private static function createRemoteCache(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::REMOTE_ACTOR_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('url_hash', 64)
                ->addLongText('actor_url', nullable: false)
                ->addString('actor_type', 16)
                ->addString('preferred_username', 255)
                ->addString('display_name', 255)
                ->addLongText('avatar_url')
                ->addLongText('inbox_url', nullable: false)
                ->addLongText('shared_inbox_url')
                ->addLongText('featured_url')
                ->addLongText('public_key_id', nullable: false)
                ->addLongText('public_key_pem', nullable: false)
                ->addLongText('also_known_as_json', nullable: false)
                ->addInteger('current_snapshot_id', true, true, null)
                ->addString('state', 16, default: 'active')
                ->addLongText('moved_to_url')
                ->addInteger('moved_at', true, true, null)
                ->addInteger('failure_count', true)
                ->addInteger('fetched_at', true)
                ->addInteger('expires_at', true)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addUniqueIndex('url_hash_idx', ['url_hash'])
                ->addIndex('expiry_idx', ['state', 'expires_at'])
            ;
        });

        $dbLayer->createTable(self::REMOTE_OBJECT_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('url_hash', 64)
                ->addLongText('object_url', nullable: false)
                ->addInteger('owner_actor_id', true)
                ->addString('object_type', 16)
                ->addString('in_reply_to_hash', 64, true, null)
                ->addLongText('in_reply_to_url')
                ->addString('visibility', 16)
                ->addString('state', 16, default: 'live')
                ->addInteger('current_snapshot_id', true, true, null)
                ->addInteger('published_at', true, true, null)
                ->addInteger('remote_updated_at', true, true, null)
                ->addInteger('deleted_at', true, true, null)
                ->addInteger('featured_at', true, true, null)
                ->addInteger('fetched_at', true)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addUniqueIndex('url_hash_idx', ['url_hash'])
                ->addIndex('owner_state_idx', ['owner_actor_id', 'state'])
                ->addIndex('reader_idx', ['visibility', 'published_at', 'id'])
                ->addIndex('reply_idx', ['in_reply_to_hash'])
                ->addForeignKey('fk_owner_actor', ['owner_actor_id'], self::REMOTE_ACTOR_TABLE, ['id'])
            ;
        });

        $dbLayer->createTable(self::REMOTE_SNAPSHOT_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('subject_type', 16)
                ->addInteger('subject_id', true)
                ->addString('body_hash', 64)
                ->addLongText('document_json', nullable: false)
                ->addString('verification_state', 16)
                ->addInteger('fetched_at', true)
                ->addInteger('retain_until', true)
                ->addUniqueIndex('subject_body_idx', ['subject_type', 'subject_id', 'body_hash'])
                ->addIndex('retention_idx', ['retain_until'])
            ;
        });

        $dbLayer->createTable(self::REMOTE_RECIPIENT_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addInteger('remote_object_id', true)
                ->addInteger('local_actor_id', true)
                ->addString('recipient_kind', 16)
                ->addInteger('created_at', true)
                ->setPrimaryKey(['remote_object_id', 'local_actor_id'])
                ->addIndex('reader_idx', ['local_actor_id', 'recipient_kind', 'remote_object_id'])
                ->addForeignKey('fk_remote_object', ['remote_object_id'], self::REMOTE_OBJECT_TABLE, ['id'], 'CASCADE')
                ->addForeignKey('fk_local_actor', ['local_actor_id'], self::ACTOR_TABLE, ['id'])
            ;
        });

        self::createRemoteMedia($dbLayer);
    }

    /** Kept public so the versioned migrator can idempotently install this table. */
    public static function createRemoteMedia(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::REMOTE_MEDIA_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('public_id', 22)
                ->addInteger('remote_actor_id', true)
                ->addString('source_url_hash', 64)
                ->addLongText('source_url', nullable: false)
                ->addString('published_source_hash', 64)
                ->addLongText('request_url', nullable: false)
                ->addInteger('redirect_count', true)
                ->addLongText('redirect_chain_json', nullable: false)
                ->addString('state', 16, default: 'pending')
                ->addInteger('attempt_count', true)
                ->addInteger('available_at', true)
                ->addInteger('give_up_at', true)
                ->addString('claim_token', 32, true, null)
                ->addInteger('claimed_at', true, true, null)
                ->addString('storage_key', 255)
                ->addString('content_type', 32)
                ->addString('content_hash', 64)
                ->addInteger('byte_size', true)
                ->addInteger('width', true)
                ->addInteger('height', true)
                ->addLongText('etag')
                ->addString('last_modified', 128)
                ->addInteger('fetched_at', true, true, null)
                ->addInteger('refresh_at', true)
                ->addInteger('serve_until', true)
                ->addInteger('last_http_status', true, true, null)
                ->addString('error_code', 64)
                ->addLongText('last_error')
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addUniqueIndex('public_id_idx', ['public_id'])
                ->addUniqueIndex('remote_actor_idx', ['remote_actor_id'])
                ->addIndex('runnable_idx', ['state', 'available_at', 'id'])
                ->addIndex('refresh_idx', ['state', 'refresh_at', 'id'])
                ->addIndex('retention_idx', ['serve_until', 'id'])
                ->addForeignKey('fk_remote_actor', ['remote_actor_id'], self::REMOTE_ACTOR_TABLE, ['id'], 'CASCADE')
            ;
        });
    }

    private static function createFollows(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::FOLLOW_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('direction', 16)
                ->addInteger('local_actor_id', true)
                ->addInteger('remote_actor_id', true)
                ->addString('state', 16, default: 'pending')
                ->addLongText('follow_activity_url', nullable: false)
                ->addString('follow_activity_hash', 64)
                ->addInteger('local_activity_id', true, true, null)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addInteger('accepted_at', true, true, null)
                ->addInteger('ended_at', true, true, null)
                ->addUniqueIndex('relationship_idx', ['direction', 'local_actor_id', 'remote_actor_id'])
                ->addIndex('local_state_idx', ['local_actor_id', 'direction', 'state'])
                ->addIndex('remote_state_idx', ['remote_actor_id', 'direction', 'state'])
                ->addForeignKey('fk_local_actor', ['local_actor_id'], self::ACTOR_TABLE, ['id'])
                ->addForeignKey('fk_remote_actor', ['remote_actor_id'], self::REMOTE_ACTOR_TABLE, ['id'])
                ->addForeignKey('fk_local_activity', ['local_activity_id'], self::ACTIVITY_TABLE, ['id'])
            ;
        });
    }

    private static function createDelivery(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::DELIVERY_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addInteger('activity_id', true)
                ->addString('inbox_url_hash', 64)
                ->addLongText('inbox_url', nullable: false)
                ->addLongText('request_url', nullable: false)
                ->addInteger('redirect_count', true)
                ->addLongText('redirect_chain_json', nullable: false)
                ->addString('effective_origin', 255)
                ->addString('origin_hash', 64)
                ->addLongText('recipient_json', nullable: false)
                ->addString('state', 16, default: 'pending')
                ->addInteger('attempt_count', true)
                ->addInteger('auth_refresh_count', true)
                ->addInteger('available_at', true)
                ->addString('claim_token', 32, true, null)
                ->addInteger('claimed_at', true, true, null)
                ->addInteger('last_attempt_at', true, true, null)
                ->addInteger('delivered_at', true, true, null)
                ->addInteger('expires_at', true)
                ->addInteger('http_status', true, true, null)
                ->addString('error_code', 64)
                ->addLongText('last_error')
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addUniqueIndex('activity_inbox_idx', ['activity_id', 'inbox_url_hash'])
                ->addIndex('runnable_idx', ['state', 'available_at', 'id'])
                ->addIndex('claim_idx', ['claim_token', 'claimed_at'])
                ->addIndex('origin_state_idx', ['origin_hash', 'state'])
                ->addForeignKey('fk_activity', ['activity_id'], self::ACTIVITY_TABLE, ['id'])
            ;
        });

        $dbLayer->createTable(self::DELIVERY_ATTEMPT_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addInteger('delivery_id', true)
                ->addInteger('attempt_number', true)
                ->addString('result', 16)
                ->addInteger('http_status', true, true, null)
                ->addString('error_code', 64)
                ->addLongText('detail')
                ->addInteger('started_at', true)
                ->addInteger('completed_at', true)
                ->addInteger('compact_after', true)
                ->addUniqueIndex('delivery_attempt_idx', ['delivery_id', 'attempt_number'])
                ->addIndex('compaction_idx', ['compact_after'])
                ->addForeignKey('fk_delivery', ['delivery_id'], self::DELIVERY_TABLE, ['id'], 'CASCADE')
            ;
        });
    }

    private static function createInteractions(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::INTERACTION_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('interaction_type', 16)
                ->addInteger('remote_actor_id', true)
                ->addString('remote_activity_hash', 64)
                ->addLongText('remote_activity_url', nullable: false)
                ->addString('remote_object_hash', 64, true, null)
                ->addLongText('remote_object_url')
                ->addInteger('local_object_id', true, true, null)
                ->addInteger('local_note_id', true, true, null)
                ->addInteger('local_comment_id', true, true, null)
                ->addString('reaction_source_key', 128)
                ->addString('emoji', 64)
                ->addBoolean('is_public')
                ->addString('state', 16, default: 'active')
                ->addLongText('provenance_json', nullable: false)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addInteger('ended_at', true, true, null)
                ->addUniqueIndex('remote_activity_idx', ['remote_activity_hash'])
                ->addIndex('local_active_idx', ['local_object_id', 'interaction_type', 'state'])
                ->addIndex('public_reply_idx', ['local_object_id', 'interaction_type', 'is_public', 'created_at', 'id'])
                ->addIndex('public_note_reply_idx', ['local_note_id', 'interaction_type', 'is_public', 'created_at', 'id'])
                ->addIndex('remote_actor_idx', ['remote_actor_id', 'state'])
                ->addForeignKey('fk_remote_actor', ['remote_actor_id'], self::REMOTE_ACTOR_TABLE, ['id'])
                ->addForeignKey('fk_local_object', ['local_object_id'], self::OBJECT_TABLE, ['id'])
                ->addForeignKey('fk_local_note', ['local_note_id'], self::LOCAL_NOTE_TABLE, ['id'])
            ;
        });

        $dbLayer->createTable(self::LOCAL_INTERACTION_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addInteger('local_actor_id', true)
                ->addInteger('remote_actor_id', true)
                ->addString('remote_object_hash', 64)
                ->addLongText('remote_object_url', nullable: false)
                ->addString('interaction_type', 16)
                ->addString('emoji', 64)
                ->addString('emoji_hash', 64)
                ->addString('state', 16, default: 'active')
                ->addInteger('local_activity_id', true)
                ->addInteger('undo_activity_id', true, true, null)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addInteger('ended_at', true, true, null)
                ->addUniqueIndex('relationship_idx', [
                    'local_actor_id',
                    'remote_object_hash',
                    'interaction_type',
                    'emoji_hash',
                ])
                ->addIndex('remote_actor_state_idx', ['remote_actor_id', 'state'])
                ->addForeignKey('fk_local_actor', ['local_actor_id'], self::ACTOR_TABLE, ['id'])
                ->addForeignKey('fk_remote_actor', ['remote_actor_id'], self::REMOTE_ACTOR_TABLE, ['id'])
                ->addForeignKey('fk_activity', ['local_activity_id'], self::ACTIVITY_TABLE, ['id'])
                ->addForeignKey('fk_undo_activity', ['undo_activity_id'], self::ACTIVITY_TABLE, ['id'])
            ;
        });
    }

    private static function createModerationAndOperations(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::MODERATION_RULE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('scope', 16)
                ->addString('match_hash', 64)
                ->addLongText('match_value', nullable: false)
                ->addString('action', 16)
                ->addInteger('priority', true)
                ->addBoolean('enabled', default: true)
                ->addLongText('rule_data', nullable: false)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addUniqueIndex('scope_match_idx', ['scope', 'match_hash'])
                ->addIndex('evaluation_idx', ['enabled', 'priority', 'id'])
            ;
        });

        $dbLayer->createTable(self::NOTIFICATION_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addInteger('local_actor_id', true, true, null)
                ->addString('notification_type', 32)
                ->addString('subject_type', 16)
                ->addInteger('subject_id', true)
                ->addString('state', 16, default: 'unread')
                ->addLongText('payload_json', nullable: false)
                ->addInteger('created_at', true)
                ->addInteger('read_at', true, true, null)
                ->addIndex('actor_state_idx', ['local_actor_id', 'state', 'created_at'])
                ->addIndex('subject_idx', ['subject_type', 'subject_id'])
                ->addForeignKey('fk_local_actor', ['local_actor_id'], self::ACTOR_TABLE, ['id'])
            ;
        });

        $dbLayer->createTable(self::RATE_LIMIT_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('bucket_hash', 64)
                ->addString('dimension', 16)
                ->addInteger('window_started_at', true)
                ->addInteger('request_count', true)
                ->addInteger('blocked_until', true)
                ->addInteger('updated_at', true)
                ->setPrimaryKey(['bucket_hash'])
                ->addIndex('expiry_idx', ['blocked_until', 'updated_at'])
            ;
        });
    }

    private static function seedState(DbLayer $dbLayer): void
    {
        $exists = (int)$dbLayer->select('COUNT(*)')
            ->from(self::STATE_TABLE)
            ->where('id = :id')->setParameter('id', 'installation')
            ->execute()
            ->result() > 0;
        if ($exists) {
            return;
        }

        $now = time();
        $dbLayer->insert(self::STATE_TABLE)
            ->values([
                'id'              => ':id',
                'profile_version' => (string)self::PROFILE_VERSION,
                'lifecycle_state' => ':lifecycle_state',
                'created_at'      => ':created_at',
                'updated_at'      => ':updated_at',
            ])
            ->execute([
                'id'              => 'installation',
                'lifecycle_state' => 'installed',
                'created_at'      => $now,
                'updated_at'      => $now,
            ])
        ;
    }
}
