<?php

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Auth\PublicAuthSchema;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\SchemaBuilderInterface;
use Register\Schema\CommentConfirmationReceiptSchemaMigration;

final class CommentConfirmationReceiptSchemaMigrationTest extends Unit
{
    public function testAddsReceiptWithoutAssumingOlderLinksSucceededAndIsRetrySafe(): void
    {
        $db = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        $db->createTable(PublicAuthSchema::MAGIC_LINKS_TABLE, static function (SchemaBuilderInterface $table): void {
            $table->addString('token_hash', 64)->addLongText('comment_text')->addInteger('used_at', true, true, null)->setPrimaryKey(['token_hash']);
        });
        $db->query("INSERT INTO auth_magic_links VALUES ('old-token', 'Preserve this comment', 123)");

        $migration = new CommentConfirmationReceiptSchemaMigration();
        self::assertSame(38, $migration->fromGeneration());
        self::assertSame(39, $migration->toGeneration());
        $migration->migrate($db);
        $migration->migrate($db);
        self::assertTrue($db->fieldExists(PublicAuthSchema::MAGIC_LINKS_TABLE, 'confirmed_comment_id'));
        self::assertSame([
            'comment_text' => 'Preserve this comment',
            'used_at' => 123,
            'confirmed_comment_id' => null,
        ], $db->select('comment_text', 'used_at', 'confirmed_comment_id')->from(PublicAuthSchema::MAGIC_LINKS_TABLE)->execute()->fetchAssoc());
    }
}
