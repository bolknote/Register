<?php
/**
 * Anonymous visitor identity
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use Register\Module\BaseModuleInstallerInterface;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

final class Manifest implements BaseModuleInstallerInterface
{
    public const string SECRET_CONFIG_KEY = 'REGISTER_VISITOR_SECRET';

    public const string VISITOR_TABLE = 'register_visitor';

    public const string USER_LINK_TABLE = 'register_visitor_user';

    /** Retained as an empty legacy table so generation-13 databases keep a compatible shape. */
    public const string FINGERPRINT_TABLE = 'register_visitor_fingerprint';

    #[\Override]
    public function getTitle(): string
    {
        return 'Anonymous visitor identity';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Evgeny Stepanischev';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Maintains a signed anonymous visitor identifier in first-party browser storage.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '1.1dev';
    }

    #[\Override]
    public function installFresh(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::VISITOR_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('visitor_id', 32)
                ->addInteger('created_at', true)
                ->addInteger('last_seen_at', true)
                ->setPrimaryKey(['visitor_id'])
                ->addIndex('last_seen_idx', ['last_seen_at'])
            ;
        });

        // Fingerprinting was removed from runtime. Keep the former table empty until the next
        // explicit schema generation so existing pre-release installations do not need a migration.
        $dbLayer->createTable(self::FINGERPRINT_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('fingerprint_hash', 64)
                ->addString('visitor_id', 32)
                ->addInteger('created_at', true)
                ->addInteger('last_seen_at', true)
                ->setPrimaryKey(['fingerprint_hash'])
                ->addIndex('visitor_idx', ['visitor_id'])
                ->addForeignKey(
                    'fk_visitor',
                    ['visitor_id'],
                    self::VISITOR_TABLE,
                    ['visitor_id'],
                    'CASCADE',
                )
            ;
        });

        VisitorUserSchema::create($dbLayer);

        $dbLayer->insert('config')
            ->setValue('name', ':name')->setParameter('name', self::SECRET_CONFIG_KEY)
            ->setValue('value', ':value')->setParameter('value', bin2hex(random_bytes(32)))
            ->onConflictDoNothing('name')
            ->execute()
        ;
    }
}
