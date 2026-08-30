<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Pdo\DbLayer;

/** Stores a compact exact-value distribution so Web Vitals reports can calculate p75. */
final readonly class AnalyticsWebVitalsDistribution
{
    private const array DEFINITIONS = [
        'lcp' => [
            'property' => 'lcp_ms',
            'maximum'  => 120000,
            'good'     => 2500,
            'needs'    => 4000,
            'unit'     => 'ms',
            'divisor'  => 1,
        ],
        'cls' => [
            'property' => 'cls_milli',
            'maximum'  => 10000,
            'good'     => 100,
            'needs'    => 250,
            'unit'     => '',
            'divisor'  => 1000,
        ],
        'inp' => [
            'property' => 'inp_ms',
            'maximum'  => 60000,
            'good'     => 200,
            'needs'    => 500,
            'unit'     => 'ms',
            'divisor'  => 1,
        ],
    ];

    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * @return array<string, array{property: string, maximum: int, good: int, needs: int, unit: string, divisor: int}>
     */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array<string, int> */
    public static function values(string $propertiesJson): array
    {
        try {
            $properties = json_decode($propertiesJson, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($properties) || array_is_list($properties)) {
            return [];
        }

        $values = [];
        foreach (self::DEFINITIONS as $metric => $definition) {
            $value = self::boundedValue($properties[$definition['property']] ?? null, $definition['maximum']);
            if ($value !== null) {
                $values[$metric] = $value;
            }
        }

        return $values;
    }

    public static function grade(string $metric, int $value): string
    {
        $definition = self::DEFINITIONS[$metric] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException('Unknown Web Vital metric.');
        }

        return $value <= $definition['good']
            ? 'good'
            : ($value <= $definition['needs'] ? 'needs' : 'poor');
    }

    private static function boundedValue(mixed $value, int $maximum): ?int
    {
        if (!\is_int($value) && !\is_float($value)) {
            return null;
        }

        $value = (int)round($value);

        return $value >= 0 && $value <= $maximum ? $value : null;
    }

    /** @param array<string, int> $values */
    public function record(int $occurredAt, string $pageKey, array $values): void
    {
        if ($occurredAt <= 0 || $values === []) {
            return;
        }

        $day = date('Y-m-d', $occurredAt);
        foreach (array_unique([AnalyticsIngestor::GLOBAL_KEY, $pageKey]) as $currentPageKey) {
            foreach ($values as $metric => $value) {
                if (!isset(self::DEFINITIONS[$metric])) {
                    continue;
                }

                $this->dbLayer->insert(AnalyticsSchema::PERFORMANCE_VALUE_TABLE)
                    ->setValue('bucket', ':bucket')->setParameter('bucket', $day)
                    ->setValue('page_key', ':page_key')->setParameter('page_key', $currentPageKey)
                    ->setValue('metric', ':metric')->setParameter('metric', $metric)
                    ->setValue('value', ':value')->setParameter('value', $value)
                    ->setValue('sample_count', ':sample_count')->setParameter('sample_count', 0)
                    ->onConflictDoNothing('bucket', 'page_key', 'metric', 'value')
                    ->execute();
                $this->dbLayer->update(AnalyticsSchema::PERFORMANCE_VALUE_TABLE)
                    ->set('sample_count', 'sample_count + :sample_count')->setParameter('sample_count', 1)
                    ->where('bucket = :bucket')->setParameter('bucket', $day)
                    ->andWhere('page_key = :page_key')->setParameter('page_key', $currentPageKey)
                    ->andWhere('metric = :metric')->setParameter('metric', $metric)
                    ->andWhere('value = :value')->setParameter('value', $value)
                    ->execute();
            }
        }
    }

    /** Rebuilds the distribution from raw events still covered by the retention window. */
    public function rebuildFromRetainedEvents(): void
    {
        $this->dbLayer->delete(AnalyticsSchema::PERFORMANCE_VALUE_TABLE)->execute();
        $result = $this->dbLayer->select('occurred_at, page_key, properties_json')
            ->from(AnalyticsSchema::EVENT_TABLE)
            ->where('event_type = :event_type')->setParameter('event_type', AnalyticsEvent::TYPE_CUSTOM)
            ->andWhere('event_name = :event_name')->setParameter(
                'event_name',
                AnalyticsBlogProjector::EVENT_WEB_VITALS,
            )
            ->orderBy('occurred_at', 'event_id')
            ->execute();
        while (($row = $result->fetchAssoc()) !== false) {
            $this->record(
                (int)$row['occurred_at'],
                (string)$row['page_key'],
                self::values((string)$row['properties_json']),
            );
        }
    }
}
