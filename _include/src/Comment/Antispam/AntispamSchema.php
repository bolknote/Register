<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;
use S2\Cms\Pdo\SchemaBuilderInterface;

final class AntispamSchema
{
    /**
     * @throws DbLayerException
     */
    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable('spam_assessments', function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('target_type', 20, true, null)
                ->addInteger('comment_id', true, true, null)
                ->addInteger('created_at', true)
                ->addString('source', 20)
                ->addInteger('score')
                ->addString('status', 20)
                ->addString('shadow_status', 20, true, null)
                ->addText('reasons', false)
                ->addString('text_hash', 64)
                ->addString('email_hash', 64)
                ->addString('ip_hash', 64)
                ->addString('moderator_label', 10, true, null)
                ->addString('model_version', 30)
                ->addIndex('target_idx', ['target_type', 'comment_id'])
                ->addIndex('created_idx', ['created_at'])
                ->addIndex('status_idx', ['status'])
                ->addIndex('label_idx', ['moderator_label'])
                ->addIndex('text_hash_idx', ['text_hash'])
            ;
        });

        $dbLayer->createTable('spam_reputation', function (SchemaBuilderInterface $table): void {
            $table
                ->addString('key_type', 20, default: null)
                ->addString('key_hash', 64, default: null)
                ->addInteger('ham_count', true)
                ->addInteger('spam_count', true)
                ->addInteger('last_seen', true)
                ->addInteger('expires_at', true)
                ->setPrimaryKey(['key_type', 'key_hash'])
                ->addIndex('expires_idx', ['expires_at'])
            ;
        });

        $dbLayer->createTable('spam_rate_events', function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('bucket_type', 20)
                ->addString('bucket_key', 64)
                ->addInteger('created_at', true)
                ->addIndex('bucket_idx', ['bucket_type', 'bucket_key', 'created_at'])
                ->addIndex('created_idx', ['created_at'])
            ;
        });

        $dbLayer->createTable('spam_form_nonces', function (SchemaBuilderInterface $table): void {
            $table
                ->addString('nonce_hash', 64, default: null)
                ->addInteger('expires_at', true)
                ->setPrimaryKey(['nonce_hash'])
                ->addIndex('expires_idx', ['expires_at'])
            ;
        });

        $dbLayer->createTable('spam_rules', function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('type', 20)
                ->addString('pattern', 255)
                ->addInteger('weight')
                ->addString('action', 20)
                ->addBoolean('enabled', false, true)
                ->addInteger('expires_at', true, true, null)
                ->addText('note', false)
                ->addIndex('active_idx', ['enabled', 'expires_at'])
            ;
        });

        self::createPolicies($dbLayer);
    }

    /**
     * @throws DbLayerException
     */
    public static function createPolicies(DbLayer $dbLayer): void
    {
        $dbLayer->createTable('spam_signal_policies', function (SchemaBuilderInterface $table): void {
            $table
                ->addString('signal', 50, default: null)
                ->addInteger('weight')
                ->addBoolean('enabled', false, true)
                ->setPrimaryKey(['signal'])
            ;
        });

        $dbLayer->createTable('spam_rate_policies', function (SchemaBuilderInterface $table): void {
            $table
                ->addString('bucket_type', 20, default: null)
                ->addInteger('request_limit', true)
                ->addInteger('window_seconds', true)
                ->addBoolean('enabled', false, true)
                ->setPrimaryKey(['bucket_type'])
            ;
        });

        foreach (SpamSignalPolicyRepository::DEFAULT_WEIGHTS as $signal => $weight) {
            $dbLayer
                ->insert('spam_signal_policies')
                ->setValue('signal', ':signal')->setParameter('signal', $signal)
                ->setValue('weight', ':weight')->setParameter('weight', $weight)
                ->setValue('enabled', '1')
                ->onConflictDoNothing('signal')
                ->execute()
            ;
        }

        foreach (SpamRatePolicyRepository::DEFAULT_POLICIES as $bucketType => $policy) {
            $dbLayer
                ->insert('spam_rate_policies')
                ->setValue('bucket_type', ':bucket_type')->setParameter('bucket_type', $bucketType)
                ->setValue('request_limit', ':request_limit')->setParameter('request_limit', $policy['limit'])
                ->setValue('window_seconds', ':window_seconds')->setParameter('window_seconds', $policy['window'])
                ->setValue('enabled', '1')
                ->onConflictDoNothing('bucket_type')
                ->execute()
            ;
        }
    }

    /**
     * @throws DbLayerException
     */
    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable('spam_rate_policies');
        $dbLayer->dropTable('spam_signal_policies');
        $dbLayer->dropTable('spam_rules');
        $dbLayer->dropTable('spam_form_nonces');
        $dbLayer->dropTable('spam_rate_events');
        $dbLayer->dropTable('spam_reputation');
        $dbLayer->dropTable('spam_assessments');
    }
}
