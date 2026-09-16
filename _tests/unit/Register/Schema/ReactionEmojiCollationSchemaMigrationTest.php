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
use Register\Module\Reactions\ReactionAggregateSchema;
use Register\Schema\ReactionEmojiCollationSchemaMigration;

final class ReactionEmojiCollationSchemaMigrationTest extends Unit
{
    public function testReplacesHashWithExactEmojiComparison(): void
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
                    ->addString('emoji_hash', 64)
                    ->addInteger('reaction_count', true)
                    ->addInteger('created_at', true)
                    ->addText('source_data')
                    ->setPrimaryKey(['target_type', 'target_id', 'source', 'source_key'])
                ;
            },
        );
        foreach ([['eyes', '👀', 2], ['down', '👎', 1]] as [$sourceKey, $emoji, $count]) {
            $dbLayer->insert(ReactionAggregateSchema::TABLE_NAME)->values([
                'target_type'    => "'post'",
                'target_id'      => '7833',
                'source'         => "'telegram'",
                'source_key'     => ':source_key',
                'reaction'       => "''",
                'emoji'          => ':emoji',
                'emoji_hash'     => ':emoji_hash',
                'reaction_count' => ':reaction_count',
                'created_at'     => '100',
                'source_data'    => "'{}'",
            ])->execute([
                'source_key'     => $sourceKey,
                'emoji'          => $emoji,
                'emoji_hash'     => hash('sha256', $emoji),
                'reaction_count' => $count,
            ]);
        }

        $migration = new ReactionEmojiCollationSchemaMigration();
        self::assertSame(32, $migration->fromGeneration());
        self::assertSame(33, $migration->toGeneration());
        $migration->migrate($dbLayer);

        self::assertFalse($dbLayer->fieldExists(ReactionAggregateSchema::TABLE_NAME, 'emoji_hash'));
        $rows = $dbLayer
            ->select('emoji', 'SUM(reaction_count) AS reaction_count')
            ->from(ReactionAggregateSchema::TABLE_NAME)
            ->groupBy('emoji')
            ->execute()
            ->fetchAssocAll()
        ;
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['emoji']] = (int)$row['reaction_count'];
        }

        ksort($counts);
        self::assertSame(['👀' => 2, '👎' => 1], $counts);

        $tableSql = $dbLayer
            ->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name", [
                'name' => ReactionAggregateSchema::TABLE_NAME,
            ])
            ->result()
        ;
        self::assertIsString($tableSql);
        self::assertStringContainsString('emoji VARCHAR(64) COLLATE BINARY NOT NULL', $tableSql);
    }
}
