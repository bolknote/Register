<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;

/**
 * Read-only reaction totals imported from external services.
 *
 * They deliberately do not use synthetic visitor identities: an archive commonly contains only
 * totals, not a complete and stable list of people who reacted. The polymorphic target also lets
 * the same table preserve reactions to comments as well as to posts and permanent pages.
 */
final class ReactionAggregateSchema
{
    public const string TABLE_NAME = 'register_reaction_aggregate';

    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addString('target_type', 16)
                ->addInteger('target_id', true)
                ->addString('source', 32)
                ->addString('source_key', 128)
                ->addString('reaction', 16)
                ->addString('emoji', 64)
                ->addInteger('reaction_count', true)
                ->addInteger('created_at', true)
                ->addText('source_data')
                ->setPrimaryKey(['target_type', 'target_id', 'source', 'source_key'])
                ->addIndex('target_idx', ['target_type', 'target_id'])
                ->addIndex('source_idx', ['source'])
            ;
        });
    }
}
