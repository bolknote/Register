<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use Register\Auth\PublicAuthSchema;
use Register\Comment\CommentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Stores durable associations between a browser visitor and authenticated users. */
final class VisitorUserSchema
{
    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(Manifest::USER_LINK_TABLE, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('visitor_id', 32)
                ->addInteger('user_id', true)
                ->addInteger('first_seen_at', true)
                ->addInteger('last_seen_at', true)
                ->setPrimaryKey(['visitor_id', 'user_id'])
                ->addIndex('user_visitor_idx', ['user_id', 'visitor_id'])
                ->addIndex('last_seen_idx', ['last_seen_at'])
                ->addForeignKey(
                    'fk_visitor',
                    ['visitor_id'],
                    Manifest::VISITOR_TABLE,
                    ['visitor_id'],
                    'CASCADE',
                )
                ->addForeignKey('fk_user', ['user_id'], 'users', ['id'], 'CASCADE')
            ;
        });

        $dbLayer->addField(
            CommentSchema::TABLE_NAME,
            'visitor_id',
            SchemaBuilderInterface::TYPE_STRING,
            32,
            true,
        );
        $dbLayer->addIndex(CommentSchema::TABLE_NAME, 'visitor_content_idx', [
            'visitor_id',
            'content_type',
            'content_id',
        ]);
        $dbLayer->addForeignKey(
            CommentSchema::TABLE_NAME,
            'fk_visitor',
            ['visitor_id'],
            Manifest::VISITOR_TABLE,
            ['visitor_id'],
            'SET NULL',
        );

        $dbLayer->addField(
            PublicAuthSchema::MAGIC_LINKS_TABLE,
            'visitor_id',
            SchemaBuilderInterface::TYPE_STRING,
            32,
            true,
        );
        $dbLayer->addForeignKey(
            PublicAuthSchema::MAGIC_LINKS_TABLE,
            'fk_visitor',
            ['visitor_id'],
            Manifest::VISITOR_TABLE,
            ['visitor_id'],
            'SET NULL',
        );
    }
}
