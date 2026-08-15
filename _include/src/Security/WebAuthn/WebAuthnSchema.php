<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Security\WebAuthn;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;

final class WebAuthnSchema
{
    public const string USER_HANDLE_TABLE = 'webauthn_user_handles';

    public const string CREDENTIAL_TABLE = 'webauthn_credentials';

    public const string CHALLENGE_TABLE = 'webauthn_challenges';

    public const string RECOVERY_CODE_TABLE = 'webauthn_recovery_codes';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::USER_HANDLE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addInteger('user_id', true, default: null)
                ->addString('user_handle', 86, default: null)
                ->addInteger('created_at', true)
                ->setPrimaryKey(['user_id'])
                ->addUniqueIndex('handle_idx', ['user_handle'])
                ->addForeignKey('fk_user', ['user_id'], 'users', ['id'], 'CASCADE')
            ;
        });

        $dbLayer->createTable(self::CREDENTIAL_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('credential_hash', 64, default: null)
                ->addInteger('user_id', true)
                ->addLongText('record', nullable: false)
                ->addString('name', 100)
                ->addInteger('created_at', true)
                ->addInteger('last_used_at', true, true, null)
                ->setPrimaryKey(['credential_hash'])
                ->addIndex('user_idx', ['user_id', 'created_at'])
                ->addForeignKey('fk_user', ['user_id'], 'users', ['id'], 'CASCADE')
            ;
        });

        $dbLayer->createTable(self::CHALLENGE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('token_hash', 64, default: null)
                ->addString('purpose', 32)
                ->addString('challenge', 86)
                ->addInteger('user_id', true, true, null)
                ->addString('session_hash', 32, true, null)
                ->addString('binding_hash', 64)
                ->addText('context', nullable: false)
                ->addInteger('created_at', true)
                ->addInteger('expires_at', true)
                ->setPrimaryKey(['token_hash'])
                ->addIndex('expiry_idx', ['expires_at'])
                ->addIndex('binding_idx', ['binding_hash', 'purpose'])
                ->addForeignKey('fk_user', ['user_id'], 'users', ['id'], 'CASCADE')
            ;
        });

        $dbLayer->createTable(self::RECOVERY_CODE_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('code_hash', 64, default: null)
                ->addInteger('user_id', true)
                ->addInteger('created_at', true)
                ->addInteger('used_at', true, true, null)
                ->setPrimaryKey(['code_hash'])
                ->addIndex('user_idx', ['user_id', 'used_at'])
                ->addForeignKey('fk_user', ['user_id'], 'users', ['id'], 'CASCADE')
            ;
        });
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::RECOVERY_CODE_TABLE);
        $dbLayer->dropTable(self::CHALLENGE_TABLE);
        $dbLayer->dropTable(self::CREDENTIAL_TABLE);
        $dbLayer->dropTable(self::USER_HANDLE_TABLE);
    }
}
