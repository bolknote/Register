<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Schema;

use Codeception\Test\Unit;
use Register\Auth\PublicAuthSchema;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Schema\PendingCommentSpamSchemaMigration;

final class PendingCommentSpamSchemaMigrationTest extends Unit
{
    public function testPersistsPendingSpamVerdictAndIsRetrySafe(): void
    {
        $dbLayer = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        $dbLayer->query(
            'CREATE TABLE auth_magic_links (
                moderation_required INTEGER NOT NULL DEFAULT 0
            )'
        );

        $migration = new PendingCommentSpamSchemaMigration();
        self::assertSame(23, $migration->fromGeneration());
        self::assertSame(24, $migration->toGeneration());

        $migration->migrate($dbLayer);
        $migration->migrate($dbLayer);

        self::assertTrue($dbLayer->fieldExists(PublicAuthSchema::MAGIC_LINKS_TABLE, 'spam_assessment_id'));
        self::assertTrue($dbLayer->fieldExists(PublicAuthSchema::MAGIC_LINKS_TABLE, 'spam_status'));
    }
}
