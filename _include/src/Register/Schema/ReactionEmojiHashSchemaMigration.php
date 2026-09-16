<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;
use Register\Module\Reactions\ReactionAggregate;
use Register\Module\Reactions\ReactionAggregateSchema;

/** Adds a database-independent exact grouping key for imported emoji. */
final readonly class ReactionEmojiHashSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 31;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 32;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            ReactionAggregateSchema::TABLE_NAME,
            'emoji_hash',
            SchemaBuilderInterface::TYPE_STRING,
            64,
            false,
            '',
            'emoji',
        );

        $rows = $dbLayer
            ->select('target_type', 'target_id', 'source', 'source_key', 'emoji')
            ->from(ReactionAggregateSchema::TABLE_NAME)
            ->execute()
            ->fetchAssocAll()
        ;
        foreach ($rows as $row) {
            $emoji = (string)$row['emoji'];
            $dbLayer
                ->update(ReactionAggregateSchema::TABLE_NAME)
                ->set('emoji_hash', ':emoji_hash')->setParameter(
                    'emoji_hash',
                    ReactionAggregate::emojiHash($emoji),
                )
                ->where('target_type = :target_type')->setParameter(
                    'target_type',
                    (string)$row['target_type'],
                )
                ->andWhere('target_id = :target_id')->setParameter('target_id', (int)$row['target_id'])
                ->andWhere('source = :source')->setParameter('source', (string)$row['source'])
                ->andWhere('source_key = :source_key')->setParameter('source_key', (string)$row['source_key'])
                ->execute()
            ;
        }
    }
}
