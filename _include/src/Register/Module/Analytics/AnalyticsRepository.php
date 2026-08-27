<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerPostgres;
use Register\Core\Pdo\DbLayerSqlite;

final readonly class AnalyticsRepository
{
    public const string PAGE_CHANNEL = 'page';

    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function record(
        string $day,
        string $channel,
        string $fingerprint,
        int $hitWeight = 1,
        int $uniqueWeight = 1,
        bool $countRepeatedHits = true,
    ): void
    {
        $this->validateCoordinates($day, $channel);

        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('Analytics fingerprint must be a SHA-256 digest.');
        }

        if ($hitWeight < 0 || $uniqueWeight < 0) {
            throw new \InvalidArgumentException('Analytics event weights cannot be negative.');
        }

        $newVisitor = $this->dbLayer->insert('register_analytics_visitor')
            ->setValue('day', ':day')->setParameter('day', $day)
            ->setValue('channel', ':channel')->setParameter('channel', $channel)
            ->setValue('fingerprint', ':fingerprint')->setParameter('fingerprint', $fingerprint)
            ->onConflictDoNothing('day', 'channel', 'fingerprint')
            ->execute()
            ->affectedRows() > 0
        ;

        if (!$newVisitor && !$countRepeatedHits) {
            return;
        }

        $this->incrementDaily($day, $channel, $hitWeight, $newVisitor ? $uniqueWeight : 0);
    }

    public function recordHit(string $day, string $channel): void
    {
        $this->validateCoordinates($day, $channel);
        $this->incrementDaily($day, $channel, 1, 0);
    }

    private function incrementDaily(string $day, string $channel, int $hits, int $uniqueCount): void
    {
        $table = $this->dbLayer->getPrefix() . 'register_analytics_daily';
        $sql = match (true) {
            $this->dbLayer instanceof DbLayerPostgres => <<<SQL
                INSERT INTO $table (day, channel, hits, unique_count)
                VALUES (:day, :channel, :hits, :unique_count)
                ON CONFLICT (day, channel) DO UPDATE SET
                    hits = $table.hits + EXCLUDED.hits,
                    unique_count = $table.unique_count + EXCLUDED.unique_count
                SQL,
            $this->dbLayer instanceof DbLayerSqlite => <<<SQL
                INSERT INTO $table (day, channel, hits, unique_count)
                VALUES (:day, :channel, :hits, :unique_count)
                ON CONFLICT (day, channel) DO UPDATE SET
                    hits = hits + excluded.hits,
                    unique_count = unique_count + excluded.unique_count
                SQL,
            default => <<<SQL
                INSERT INTO $table (day, channel, hits, unique_count)
                VALUES (:day, :channel, :hits, :unique_count)
                ON DUPLICATE KEY UPDATE
                    hits = hits + VALUES(hits),
                    unique_count = unique_count + VALUES(unique_count)
                SQL,
        };

        $this->dbLayer->query($sql, [
            'day'          => $day,
            'channel'      => $channel,
            'hits'         => $hits,
            'unique_count' => $uniqueCount,
        ]);
    }

    private function validateCoordinates(string $day, string $channel): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1) {
            throw new \InvalidArgumentException('Analytics day must use the YYYY-MM-DD format.');
        }

        if (preg_match('/^[a-z0-9:_-]{1,64}$/D', $channel) !== 1) {
            throw new \InvalidArgumentException('Analytics channel contains unsupported characters.');
        }
    }

    public function forgetVisitorFingerprintsBefore(string $day): void
    {
        $this->dbLayer->delete('register_analytics_visitor')
            ->where('day < :day')->setParameter('day', $day)
            ->execute()
        ;
    }

    /** @return list<array{day: string, hits: int, unique_count: int}> */
    public function dailySeries(string $channel): array
    {
        $rows = $this->dbLayer->select('day, hits, unique_count')
            ->from('register_analytics_daily')
            ->where('channel = :channel')->setParameter('channel', $channel)
            ->orderBy('day')
            ->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map(static fn(array $row): array => [
            'day'          => (string)$row['day'],
            'hits'         => (int)$row['hits'],
            'unique_count' => (int)$row['unique_count'],
        ], $rows));
    }

    /** @return array{total: int, today: int, unique_today: int} */
    public function pageSummary(string $day): array
    {
        $total = $this->dbLayer->select('COALESCE(SUM(hits), 0)')
            ->from('register_analytics_daily')
            ->where('channel = :channel')->setParameter('channel', self::PAGE_CHANNEL)
            ->execute()
            ->result()
        ;

        $today = $this->dbLayer->select('hits, unique_count')
            ->from('register_analytics_daily')
            ->where('channel = :channel')->setParameter('channel', self::PAGE_CHANNEL)
            ->andWhere('day = :day')->setParameter('day', $day)
            ->execute()
            ->fetchAssoc()
        ;

        return [
            'total'        => (int)$total,
            'today'        => (int)($today['hits'] ?? 0),
            'unique_today' => (int)($today['unique_count'] ?? 0),
        ];
    }
}
