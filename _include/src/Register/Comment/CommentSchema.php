<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Core\Model\UserpicSchema;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

final class CommentSchema
{
    public const string TABLE_NAME = 'comments';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('content_type', 8)
                ->addInteger('content_id', true)
                ->addInteger('parent_id', true, true, null)
                ->addInteger('user_id', true, true, null)
                ->addString('visitor_id', 32, true, null)
            ;
            UserpicSchema::addCommentReferenceToDefinition($table);
            $table
                ->addInteger('time', true)
                ->addInteger('modify_time', true)
                ->addString('ip', 39)
                ->addString('nick', 50)
                ->addString('email', 80)
                ->addBoolean('show_email')
                ->addBoolean('subscribed')
                ->addBoolean('shown')
                ->addBoolean('deleted')
                ->addBoolean('sent')
                ->addBoolean('good')
                ->addText('text', nullable: false)
                ->addForeignKey(
                    'fk_parent',
                    ['parent_id'],
                    self::TABLE_NAME,
                    ['id'],
                    'SET NULL',
                )
                ->addForeignKey(
                    'fk_user',
                    ['user_id'],
                    'users',
                    ['id'],
                    'SET NULL',
                )
                ->addIndex('content_sort_idx', ['content_type', 'content_id', 'time', 'shown'])
                ->addIndex('thread_idx', ['content_type', 'content_id', 'parent_id', 'shown'])
                ->addIndex('moderation_idx', ['shown', 'sent', 'content_type'])
                ->addIndex('time_idx', ['time'])
                ->addIndex('user_content_idx', ['user_id', 'content_type', 'content_id', 'shown'])
            ;
        });
    }

    public static function drop(DbLayer $dbLayer): void
    {
        $dbLayer->dropTable(self::TABLE_NAME);
    }
}
