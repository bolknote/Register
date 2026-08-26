<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Analytics;

use Codeception\Test\Unit;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PDO;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Module\Analytics\AnalyticsMaintenanceTask;
use Register\Module\Analytics\AnalyticsRepository;

final class AnalyticsMaintenanceTaskTest extends Unit
{
    public function testRemovesExpiredFingerprintsDuringScheduledMaintenance(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $dbLayer->query('CREATE TABLE register_analytics_visitor (
            day VARCHAR(10) NOT NULL,
            channel VARCHAR(64) NOT NULL,
            fingerprint VARCHAR(64) NOT NULL,
            PRIMARY KEY (day, channel, fingerprint)
        )');
        $dbLayer->query(
            'INSERT INTO register_analytics_visitor (day, channel, fingerprint) VALUES
                (:old_day, :channel, :old_fingerprint),
                (:today, :channel, :today_fingerprint)',
            [
                'old_day'           => '2026-08-25',
                'today'             => '2026-08-26',
                'channel'           => AnalyticsRepository::PAGE_CHANNEL,
                'old_fingerprint'   => str_repeat('a', 64),
                'today_fingerprint' => str_repeat('b', 64),
            ],
        );

        $now = (new \DateTimeImmutable('2026-08-26T12:00:00+00:00'))->getTimestamp();
        (new AnalyticsMaintenanceTask(new AnalyticsRepository($dbLayer)))
            ->schedule($now, new QueueExecutionBudget(1.0));

        self::assertSame(['2026-08-26'], $dbLayer->select('day')
            ->from('register_analytics_visitor')
            ->execute()
            ->fetchColumn());
    }
}
