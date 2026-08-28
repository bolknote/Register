<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Comment\CommentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Persistent identities, short-lived sign-in flows and per-user comment read state. */
final class PublicAuthSchema
{
    public const string IDENTITIES_TABLE = 'auth_identities';

    public const string FLOWS_TABLE = 'auth_flows';

    public const string MAGIC_LINKS_TABLE = 'auth_magic_links';

    public const string NOTIFICATION_USERS_TABLE = 'comment_notification_users';

    public const string NOTIFICATION_READS_TABLE = 'comment_notification_reads';

    public static function create(DbLayer $dbLayer): void
    {
        self::addCommentUser($dbLayer);

        $dbLayer->createTable(self::IDENTITIES_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addInteger('user_id', true)
                ->addString('provider', 24)
                ->addString('subject', 191)
                ->addString('email', 80)
                ->addString('display_name', 80)
                ->addString('avatar_url', 1024)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addForeignKey('fk_user', ['user_id'], 'users', ['id'], 'CASCADE')
                ->addUniqueIndex('provider_subject_idx', ['provider', 'subject'])
                ->addIndex('user_idx', ['user_id'])
                ->addIndex('email_idx', ['email'])
            ;
        });

        $dbLayer->createTable(self::FLOWS_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('state_hash', 64, default: null)
                ->addString('provider', 24)
                ->addString('code_verifier', 128)
                ->addString('device_id', 80)
                ->addString('return_path', 1024)
                ->addInteger('created_at', true)
                ->addInteger('expires_at', true)
                ->setPrimaryKey(['state_hash'])
                ->addIndex('expires_idx', ['expires_at'])
            ;
        });

        $dbLayer->createTable(self::MAGIC_LINKS_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('token_hash', 64, default: null)
                ->addString('email', 80)
                ->addString('display_name', 80)
                ->addString('return_path', 1024)
                ->addString('content_type', 8)
                ->addInteger('content_id', true, true, null)
                ->addInteger('parent_id', true, true, null)
                ->addLongText('comment_text')
                ->addString('visitor_id', 32, true, null)
                ->addBoolean('subscribed')
                ->addBoolean('moderation_required')
                ->addInteger('spam_assessment_id', true, true, null)
                ->addString('spam_status', 16)
                ->addString('ip', 39)
                ->addInteger('created_at', true)
                ->addInteger('expires_at', true)
                ->addInteger('used_at', true, true, null)
                ->setPrimaryKey(['token_hash'])
                ->addIndex('expires_idx', ['expires_at', 'used_at'])
                ->addIndex('email_idx', ['email'])
            ;
        });
        self::ensureMagicLinkModerationRequirement($dbLayer);
        self::ensurePendingCommentSpamAssessment($dbLayer);

        $dbLayer->createTable(self::NOTIFICATION_USERS_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addInteger('user_id', true, default: null)
                ->addInteger('initial_comment_id', true)
                ->addInteger('created_at', true)
                ->setPrimaryKey(['user_id'])
                ->addForeignKey('fk_user', ['user_id'], 'users', ['id'], 'CASCADE')
            ;
        });

        $dbLayer->createTable(self::NOTIFICATION_READS_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addInteger('user_id', true, default: null)
                ->addInteger('comment_id', true, default: null)
                ->addInteger('read_at', true)
                ->setPrimaryKey(['user_id', 'comment_id'])
                ->addForeignKey('fk_user', ['user_id'], 'users', ['id'], 'CASCADE')
                ->addForeignKey('fk_comment', ['comment_id'], CommentSchema::TABLE_NAME, ['id'], 'CASCADE')
                ->addIndex('comment_idx', ['comment_id', 'user_id'])
            ;
        });
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::NOTIFICATION_READS_TABLE);
        $dbLayer->dropTable(self::NOTIFICATION_USERS_TABLE);
        $dbLayer->dropTable(self::MAGIC_LINKS_TABLE);
        $dbLayer->dropTable(self::FLOWS_TABLE);
        $dbLayer->dropTable(self::IDENTITIES_TABLE);
        if ($dbLayer->foreignKeyExists(CommentSchema::TABLE_NAME, 'fk_user')) {
            $dbLayer->dropForeignKey(CommentSchema::TABLE_NAME, 'fk_user');
        }

        if ($dbLayer->indexExists(CommentSchema::TABLE_NAME, 'user_content_idx')) {
            $dbLayer->dropIndex(CommentSchema::TABLE_NAME, 'user_content_idx');
        }

        if ($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'user_id')) {
            $dbLayer->dropField(CommentSchema::TABLE_NAME, 'user_id');
        }
    }

    public static function addCommentUser(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            CommentSchema::TABLE_NAME,
            'user_id',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            null,
            true,
        );
        $dbLayer->addIndex(CommentSchema::TABLE_NAME, 'user_content_idx', [
            'user_id',
            'content_type',
            'content_id',
            'shown',
        ]);
        $dbLayer->addForeignKey(
            CommentSchema::TABLE_NAME,
            'fk_user',
            ['user_id'],
            'users',
            ['id'],
            'SET NULL',
        );
    }

    /** Repairs generation-17 databases produced before pending-comment moderation was persisted. */
    public static function ensureMagicLinkModerationRequirement(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            self::MAGIC_LINKS_TABLE,
            'moderation_required',
            SchemaBuilderInterface::TYPE_BOOLEAN,
            null,
            false,
            false,
        );
    }

    /** Persists the pre-verification spam verdict until the pending comment is created. */
    public static function ensurePendingCommentSpamAssessment(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            self::MAGIC_LINKS_TABLE,
            'spam_assessment_id',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            null,
            true,
            null,
            'moderation_required',
        );
        $dbLayer->addField(
            self::MAGIC_LINKS_TABLE,
            'spam_status',
            SchemaBuilderInterface::TYPE_STRING,
            16,
            false,
            '',
            'spam_assessment_id',
        );
    }
}
