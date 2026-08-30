<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Comment;

use Codeception\Test\Unit;
use Register\Comment\CommentPublicationTrustPolicy;
use Register\Core\Pdo\DbLayerSqlite;

final class CommentPublicationTrustPolicyTest extends Unit
{
    public function testOnlyAnExternalIdentityWithoutPublishedHistoryRequiresModeration(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createTables($pdo);

        $pdo->exec("INSERT INTO users (
            id, hide_comments, edit_comments, create_articles, edit_site, edit_users
        ) VALUES
            (1, 0, 0, 0, 0, 0),
            (2, 0, 0, 0, 0, 0),
            (3, 0, 0, 1, 0, 0),
            (4, 0, 0, 0, 0, 0)");
        $pdo->exec("INSERT INTO auth_identities (user_id) VALUES (1), (2), (3)");
        $pdo->exec("INSERT INTO comments (user_id, shown, deleted) VALUES
            (2, 0, 0),
            (2, 1, 0),
            (4, 0, 0)");

        $policy = new CommentPublicationTrustPolicy(new DbLayerSqlite($pdo, ''));

        self::assertTrue($policy->requiresModeration(1));
        self::assertFalse($policy->requiresModeration(2));
        self::assertFalse($policy->requiresModeration(3));
        self::assertFalse($policy->requiresModeration(4));
        self::assertTrue($policy->requiresModeration(999));
    }

    private function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            hide_comments INTEGER NOT NULL,
            edit_comments INTEGER NOT NULL,
            create_articles INTEGER NOT NULL,
            edit_site INTEGER NOT NULL,
            edit_users INTEGER NOT NULL
        )');
        $pdo->exec('CREATE TABLE auth_identities (user_id INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE comments (
            user_id INTEGER,
            shown INTEGER NOT NULL,
            deleted INTEGER NOT NULL
        )');
    }
}
