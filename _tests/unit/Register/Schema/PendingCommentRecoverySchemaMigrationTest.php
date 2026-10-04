<?php

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Auth\PublicAuthSchema;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\SchemaBuilderInterface;
use Register\Schema\PendingCommentRecoverySchemaMigration;

final class PendingCommentRecoverySchemaMigrationTest extends Unit
{
    public function testAddsRecoveryWithoutChangingExistingCommentsAndIsRetrySafe(): void
    {
        $db = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        $db->createTable('auth_magic_links', static function (SchemaBuilderInterface $table): void {
            $table->addString('token_hash', 64)->addLongText('comment_text')->setPrimaryKey(['token_hash']);
        });
        $db->query("INSERT INTO auth_magic_links VALUES ('old-token', 'Keep the old pending comment')");

        $migration = new PendingCommentRecoverySchemaMigration();
        self::assertSame(37, $migration->fromGeneration());
        self::assertSame(38, $migration->toGeneration());
        $migration->migrate($db);
        $migration->migrate($db);
        self::assertTrue($db->fieldExists(PublicAuthSchema::MAGIC_LINKS_TABLE, 'recovery_hash'));
        self::assertTrue($db->indexExists(PublicAuthSchema::MAGIC_LINKS_TABLE, 'recovery_idx'));
        self::assertSame('Keep the old pending comment', $db->select('comment_text')->from(PublicAuthSchema::MAGIC_LINKS_TABLE)->execute()->result());
    }
}
