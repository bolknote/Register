<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\HostRequestThrottle;
use Register\Module\LinkHealth\Manifest;

final class HostRequestThrottleTest extends Unit
{
    public function testSerializesOneHostButAllowsAnotherHostImmediately(): void
    {
        $pdo          = $this->pdo();
        $firstWorker  = new HostRequestThrottle($pdo, '');
        $secondWorker = new HostRequestThrottle($pdo, '');
        $now          = 1_800_000_000;

        self::assertNull($firstWorker->claim('https://EXAMPLE.test/first', $now));
        self::assertSame($now + 2, $secondWorker->claim('https://example.test/second', $now));
        self::assertNull($secondWorker->claim('https://other.test/', $now));
        self::assertNull($firstWorker->claim('https://example.test/third', $now + 2));

        $statement = $pdo->query('SELECT COUNT(*) FROM ' . Manifest::THROTTLE_TABLE . " WHERE service LIKE 'host:%'");
        self::assertInstanceOf(\PDOStatement::class, $statement);
        self::assertSame(2, (int)$statement->fetchColumn());
    }

    public function testPrunesOnlyStaleHostSlots(): void
    {
        $pdo      = $this->pdo();
        $throttle = new HostRequestThrottle($pdo, '');
        $pdo->exec(
            "INSERT INTO " . Manifest::THROTTLE_TABLE . " (service, next_request_at) VALUES ('wayback', 0)"
        );
        $throttle->claim('https://old.example/', 100);
        $throttle->prune(100 + 30 * 86400 + 3);

        $statement = $pdo->query('SELECT service FROM ' . Manifest::THROTTLE_TABLE);
        self::assertInstanceOf(\PDOStatement::class, $statement);
        self::assertSame(['wayback'], $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec(
            'CREATE TABLE ' . Manifest::THROTTLE_TABLE
            . ' (service VARCHAR(32) PRIMARY KEY, next_request_at INTEGER NOT NULL)'
        );

        return $pdo;
    }
}
