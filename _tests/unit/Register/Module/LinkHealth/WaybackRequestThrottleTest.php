<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\Manifest;
use Register\Module\LinkHealth\WaybackRequestThrottle;

final class WaybackRequestThrottleTest extends Unit
{
    public function testAllowsOnlyOneClaimPerGlobalInterval(): void
    {
        $pdo          = $this->pdo();
        $firstWorker  = new WaybackRequestThrottle($pdo, '');
        $secondWorker = new WaybackRequestThrottle($pdo, '');

        self::assertNull($firstWorker->claim(1_800_000_000));
        self::assertSame(1_800_000_015, $secondWorker->claim(1_800_000_000));
        self::assertSame(1_800_000_015, $firstWorker->claim(1_800_000_014));
        self::assertNull($secondWorker->claim(1_800_000_015));
        $statement = $pdo->query('SELECT next_request_at FROM ' . Manifest::THROTTLE_TABLE);
        self::assertInstanceOf(\PDOStatement::class, $statement);
        self::assertSame(
            1_800_000_030,
            (int)$statement->fetchColumn(),
        );
    }

    public function testRejectsInvalidTime(): void
    {
        $throttle = new WaybackRequestThrottle($this->pdo(), '');

        $this->expectException(\InvalidArgumentException::class);
        $throttle->claim(-1);
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec(
            'CREATE TABLE ' . Manifest::THROTTLE_TABLE
            . ' (service VARCHAR(32) PRIMARY KEY, next_request_at INTEGER NOT NULL)'
        );
        $pdo->exec(
            "INSERT INTO " . Manifest::THROTTLE_TABLE . " (service, next_request_at) VALUES ('wayback', 0)"
        );

        return $pdo;
    }
}
