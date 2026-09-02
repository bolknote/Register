<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\VisitorIdentity;

use Codeception\Test\Unit;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PDO;
use Register\Module\VisitorIdentity\Manifest;
use Register\Module\VisitorIdentity\VisitorIdentityRepository;

final class VisitorIdentityRepositoryTest extends Unit
{
    public function testDoesNotUpdateRowsImmediatelyAfterInsertingThem(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $dbLayer->query('CREATE TABLE ' . Manifest::VISITOR_TABLE . ' (
            visitor_id VARCHAR(32) PRIMARY KEY,
            created_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL
        )');
        $dbLayer->query('CREATE TABLE ' . Manifest::USER_LINK_TABLE . ' (
            visitor_id VARCHAR(32) NOT NULL,
            user_id INTEGER NOT NULL,
            first_seen_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL,
            PRIMARY KEY (visitor_id, user_id)
        )');

        $repository = new VisitorIdentityRepository($dbLayer);
        $visitorId = str_repeat('a', 32);

        $pdo->clearState();
        $repository->linkUser($visitorId, 7, 100);
        self::assertSame(2, $pdo->getQueryCount());

        $pdo->clearState();
        $repository->linkUser($visitorId, 7, 200);
        self::assertSame(4, $pdo->getQueryCount());

        self::assertSame(200, (int)$dbLayer->select('last_seen_at')
            ->from(Manifest::VISITOR_TABLE)
            ->where('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->execute()
            ->result());
        self::assertSame(200, (int)$dbLayer->select('last_seen_at')
            ->from(Manifest::USER_LINK_TABLE)
            ->where('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->andWhere('user_id = :user_id')->setParameter('user_id', 7)
            ->execute()
            ->result());
    }

    public function testPurgesOnlyOldUnreferencedVisitorsInOneBatch(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $dbLayer->query('CREATE TABLE ' . Manifest::VISITOR_TABLE . ' (
            visitor_id VARCHAR(32) PRIMARY KEY,
            created_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL
        )');
        $dbLayer->query('CREATE TABLE ' . Manifest::USER_LINK_TABLE . ' (
            visitor_id VARCHAR(32) NOT NULL,
            user_id INTEGER NOT NULL,
            first_seen_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL,
            PRIMARY KEY (visitor_id, user_id)
        )');

        $repository = new VisitorIdentityRepository($dbLayer);
        $oldPassive = str_repeat('a', 32);
        $oldLinked = str_repeat('b', 32);
        $recentPassive = str_repeat('c', 32);
        $repository->touchVisitor($oldPassive, 10);
        $repository->linkUser($oldLinked, 7, 20);
        $repository->touchVisitor($recentPassive, 200);

        self::assertSame(1, $repository->purgeUnreferencedBefore(100));
        self::assertSame([$oldLinked, $recentPassive], $dbLayer
            ->select('visitor_id')
            ->from(Manifest::VISITOR_TABLE)
            ->orderBy('visitor_id')
            ->execute()
            ->fetchColumn());
        self::assertSame(0, $repository->purgeUnreferencedBefore(100));
    }
}
