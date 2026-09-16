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
use Register\Module\Reactions\ReactionAggregateSchema;

/** Groups imported emoji by their exact value and removes the temporary hash key. */
final readonly class ReactionEmojiCollationSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 32;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 33;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        $dbLayer->alterField(
            ReactionAggregateSchema::TABLE_NAME,
            'emoji',
            SchemaBuilderInterface::TYPE_EXACT_STRING,
            64,
            false,
            '',
            'reaction',
        );
        $dbLayer->dropField(ReactionAggregateSchema::TABLE_NAME, 'emoji_hash');
    }
}
