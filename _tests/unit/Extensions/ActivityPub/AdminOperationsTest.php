<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Pdo\DbLayerSqlite;
use s2_extensions\activitypub\Admin\ActivityPubAdminAccess;
use s2_extensions\activitypub\Admin\ActivityPubAdminRepository;
use s2_extensions\activitypub\Domain\ActorKind;
use s2_extensions\activitypub\Domain\ActorType;
use s2_extensions\activitypub\Domain\LocalActorState;
use s2_extensions\activitypub\Domain\LocalHandle;
use s2_extensions\activitypub\Domain\NewLocalActor;
use s2_extensions\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use s2_extensions\activitypub\Infrastructure\ActivityPubSchema;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;

final class AdminOperationsTest extends Unit
{
    public function testAuthorAdministrationIsRestrictedToTheOwnedActor(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        ActivityPubSchema::install($dbLayer);
        $actors = new LocalActorRepository($dbLayer);
        $ownActorId = $actors->insert($this->authorActor('AAAAAAAAAAAAAAAAAAAAAA', 7, 'alice'), LocalActorState::ACTIVE, 1_800_000_000);
        $otherActorId = $actors->insert($this->authorActor('BBBBBBBBBBBBBBBBBBBBBB', 8, 'bob'), LocalActorState::ACTIVE, 1_800_000_000);

        $permissions = new PermissionChecker();
        $permissions->setUser([
            'id'              => 7,
            'create_articles' => true,
            'edit_site'       => false,
        ]);
        $access = new ActivityPubAdminAccess($permissions, $actors);

        self::assertTrue($access->canAccess());
        self::assertSame(7, $access->currentAuthorId());
        self::assertTrue($access->canManageAuthor(7));
        self::assertFalse($access->canManageAuthor(8));
        self::assertTrue($access->canManageActor($ownActorId));
        self::assertFalse($access->canManageActor($otherActorId));
        self::assertTrue($access->canPerform('reply', 0, $ownActorId));
        self::assertFalse($access->canPerform('reply', 0, $otherActorId));
        self::assertFalse($access->canPerform('push_queue', 0, 0));
        self::assertFalse($access->canPerform('moderate', 0, 0));
        self::assertFalse($access->canPerform('backfill_latest', 0, 0));

        $permissions->setUser([
            'id'              => 9,
            'create_articles' => false,
            'edit_site'       => true,
        ]);
        self::assertTrue($access->canManageSite());
        self::assertTrue($access->canManageActor($otherActorId));
        self::assertTrue($access->canPerform('decommission', 0, 0));
    }

    public function testOperationalDashboardAggregatesDurableDatabaseState(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $this->createDashboardTables($pdo);
        $dbLayer = new DbLayerSqlite($pdo);
        $document = json_encode(['content' => 'Привет, Fediverse 👋'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $statement = $pdo->prepare('INSERT INTO register_ap_remote_snapshot (document_json) VALUES (:document_json)');
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $statement->execute(['document_json' => $document]);
        $pdo->exec("INSERT INTO register_ap_remote_media (state, byte_size) VALUES ('ready', 123), ('failed', 999)");
        $pdo->exec("INSERT INTO register_ap_interaction (interaction_type, state, is_public, local_comment_id) VALUES
            ('reply', 'active', 0, 10), ('reply', 'active', 1, 11), ('flag', 'active', 0, NULL)");
        $pdo->exec("INSERT INTO register_ap_moderation_rule
            (id, scope, match_value, action, priority, enabled, updated_at)
            VALUES (1, 'domain', 'blocked.example', 'block', 1000, 1, 1800000000)");
        $pdo->exec("INSERT INTO register_ap_delivery (state, effective_origin, updated_at) VALUES
            ('failed', 'https://broken.example', 1800000001),
            ('failed', 'https://broken.example', 1800000002),
            ('pending', 'https://healthy.example', 1800000003)");
        $pdo->exec("INSERT INTO register_ap_inbox (state, effective_origin, received_at) VALUES
            ('failed', 'https://hostile.example', 1800000004),
            ('processed', 'https://healthy.example', 1800000005)");

        $repository = new ActivityPubAdminRepository($dbLayer, $pdo);
        $summary = $repository->summary();
        self::assertSame(1, $summary['moderation_pending']);
        self::assertSame(1, $summary['moderation_flags']);
        self::assertSame(1, $summary['moderation_rules']);
        self::assertSame(\strlen($document) + 123, $summary['remote_cache_bytes']);
        self::assertSame([
            [
                'direction'       => 'outbound',
                'origin'          => 'https://broken.example',
                'failure_count'   => 2,
                'last_failure_at' => 1_800_000_002,
            ],
            [
                'direction'       => 'inbound',
                'origin'          => 'https://hostile.example',
                'failure_count'   => 1,
                'last_failure_at' => 1_800_000_004,
            ],
        ], $repository->failuresByDomain());
        self::assertSame('blocked.example', $repository->moderationRules()[0]['match_value']);

        $telemetry = new ActivityPubRunnerTelemetryRepository($dbLayer);
        self::assertSame(['last_runner_at' => null, 'last_runner_code' => ''], $telemetry->status());
        $telemetry->record('register_activitypub_inbox', 1_800_000_006);
        self::assertSame([
            'last_runner_at'   => 1_800_000_006,
            'last_runner_code' => 'register_activitypub_inbox',
        ], $telemetry->status());
    }

    private function authorActor(string $publicId, int $authorId, string $handle): NewLocalActor
    {
        return new NewLocalActor(
            $publicId,
            ActorKind::AUTHOR,
            $authorId,
            ActorType::PERSON,
            new LocalHandle($handle),
            ucfirst($handle),
            '<p>Author profile</p>',
            'https://example.test/authors/' . $handle,
        );
    }

    private function createDashboardTables(\PDO $pdo): void
    {
        $statements = [
            'CREATE TABLE register_ap_state (id TEXT PRIMARY KEY, last_runner_at INTEGER NULL, last_runner_code TEXT NOT NULL)',
            "INSERT INTO register_ap_state VALUES ('installation', NULL, '')",
            'CREATE TABLE register_ap_inbox (state TEXT NOT NULL, effective_origin TEXT NOT NULL, received_at INTEGER NOT NULL)',
            'CREATE TABLE register_ap_delivery (state TEXT NOT NULL, effective_origin TEXT NOT NULL, updated_at INTEGER NOT NULL)',
            'CREATE TABLE register_ap_follow (direction TEXT NOT NULL, state TEXT NOT NULL)',
            'CREATE TABLE register_ap_remote_object (state TEXT NOT NULL, visibility TEXT NOT NULL)',
            'CREATE TABLE register_ap_notification (state TEXT NOT NULL)',
            'CREATE TABLE register_ap_remote_media (state TEXT NOT NULL, byte_size INTEGER NOT NULL)',
            'CREATE TABLE register_ap_interaction (interaction_type TEXT NOT NULL, state TEXT NOT NULL, is_public INTEGER NOT NULL, local_comment_id INTEGER NULL)',
            'CREATE TABLE register_ap_moderation_rule (id INTEGER PRIMARY KEY, scope TEXT NOT NULL, match_value TEXT NOT NULL, action TEXT NOT NULL, priority INTEGER NOT NULL, enabled INTEGER NOT NULL, updated_at INTEGER NOT NULL)',
            'CREATE TABLE register_ap_remote_snapshot (document_json TEXT NOT NULL)',
        ];
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }
}
