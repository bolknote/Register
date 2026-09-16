<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\SchemaBuilderInterface;
use Register\Module\Reactions\ReactionAggregate;
use Register\Module\Reactions\ReactionAggregateSchema;
use Register\Schema\ReactionEmojiHashSchemaMigration;

final class ReactionEmojiHashSchemaMigrationTest extends Unit
{
    public function testBackfillsDatabaseIndependentEmojiHashes(): void
    {
        $dbLayer = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        $dbLayer->createTable(
            ReactionAggregateSchema::TABLE_NAME,
            static function (SchemaBuilderInterface $table): void {
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
                ;
            },
        );
        foreach ([['eyes', '👀'], ['down', '👎']] as [$sourceKey, $emoji]) {
            $dbLayer->insert(ReactionAggregateSchema::TABLE_NAME)->values([
                'target_type'   => "'post'",
                'target_id'     => '1',
                'source'        => "'telegram'",
                'source_key'    => ':source_key',
                'reaction'      => "''",
                'emoji'         => ':emoji',
                'reaction_count' => '1',
                'created_at'    => '100',
                'source_data'   => "'{}'",
            ])->execute([
                'source_key' => $sourceKey,
                'emoji'      => $emoji,
            ]);
        }

        $migration = new ReactionEmojiHashSchemaMigration();
        self::assertSame(31, $migration->fromGeneration());
        self::assertSame(32, $migration->toGeneration());
        $migration->migrate($dbLayer);

        self::assertTrue($dbLayer->fieldExists(ReactionAggregateSchema::TABLE_NAME, 'emoji_hash'));
        self::assertSame(
            [
                ['emoji' => '👎', 'emoji_hash' => ReactionAggregate::emojiHash('👎')],
                ['emoji' => '👀', 'emoji_hash' => ReactionAggregate::emojiHash('👀')],
            ],
            $dbLayer
                ->select('emoji', 'emoji_hash')
                ->from(ReactionAggregateSchema::TABLE_NAME)
                ->orderBy('source_key')
                ->execute()
                ->fetchAssocAll(),
        );
    }
}
