<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

/**
 * Reconciles all-time counters with the dated tail retained by a legacy system.
 *
 * Counts older than the available dated tail are stored on an explicit historical day. This
 * preserves exact public totals and popular-post ordering without making the unknown older traffic
 * appear in the current hot-post window.
 */
final class HistoricalContentViewPlan
{
    public const string UNDATED_HISTORY_DAY = '1970-01-01';

    /**
     * @param array<int, int> $lifetimeTotals Source content ID => all-time views.
     * @param iterable<array{content_id: int, day: string, views: int}> $datedTail
     * @return list<array{content_id: int, day: string, views: int}>
     */
    public static function build(array $lifetimeTotals, iterable $datedTail): array
    {
        $daily = [];
        $datedTotals = array_fill_keys(array_keys($lifetimeTotals), 0);
        foreach ($lifetimeTotals as $contentId => $views) {
            self::assertContentId($contentId);
            self::assertNonNegativeViews($views, $contentId, 'lifetime');
        }

        foreach ($datedTail as $row) {
            $contentId = $row['content_id'];
            $day = $row['day'];
            $views = $row['views'];
            self::assertContentId($contentId);
            if (!array_key_exists($contentId, $lifetimeTotals)) {
                throw new \UnexpectedValueException(sprintf(
                    'Dated views reference unknown source content %d.',
                    $contentId,
                ));
            }
            self::assertDay($day, $contentId);
            if ($views <= 0) {
                throw new \UnexpectedValueException(sprintf(
                    'Dated views for source content %d must be positive.',
                    $contentId,
                ));
            }

            $daily[$contentId][$day] = ($daily[$contentId][$day] ?? 0) + $views;
            $datedTotals[$contentId] += $views;
        }

        foreach ($lifetimeTotals as $contentId => $lifetimeTotal) {
            $datedTotal = $datedTotals[$contentId];
            if ($datedTotal > $lifetimeTotal) {
                throw new \UnexpectedValueException(sprintf(
                    'Dated views for source content %d exceed its lifetime total (%d > %d).',
                    $contentId,
                    $datedTotal,
                    $lifetimeTotal,
                ));
            }

            $undated = $lifetimeTotal - $datedTotal;
            if ($undated > 0) {
                $daily[$contentId][self::UNDATED_HISTORY_DAY]
                    = ($daily[$contentId][self::UNDATED_HISTORY_DAY] ?? 0) + $undated;
            }
        }

        ksort($daily, SORT_NUMERIC);
        $result = [];
        foreach ($daily as $contentId => $days) {
            ksort($days, SORT_STRING);
            foreach ($days as $day => $views) {
                $result[] = [
                    'content_id' => $contentId,
                    'day'        => $day,
                    'views'      => $views,
                ];
            }
        }

        return $result;
    }

    private static function assertContentId(int $contentId): void
    {
        if ($contentId <= 0) {
            throw new \UnexpectedValueException('Historical content IDs must be positive.');
        }
    }

    private static function assertNonNegativeViews(int $views, int $contentId, string $kind): void
    {
        if ($views < 0) {
            throw new \UnexpectedValueException(sprintf(
                'The %s view total for source content %d cannot be negative.',
                $kind,
                $contentId,
            ));
        }
    }

    private static function assertDay(string $day, int $contentId): void
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $day,
            new \DateTimeZone('UTC'),
        );
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $day) {
            throw new \UnexpectedValueException(sprintf(
                'Dated views for source content %d have an invalid UTC day: %s.',
                $contentId,
                $day,
            ));
        }
    }
}
