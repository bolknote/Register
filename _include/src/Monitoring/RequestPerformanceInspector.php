<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

/** Summarizes the bounded, privacy-minimized slow-request log for the admin dashboard. */
final readonly class RequestPerformanceInspector
{
    private const int RECENT_WINDOW_SECONDS = 3600;

    private const int DAILY_WINDOW_SECONDS = 86400;

    public function __construct(private string $logFile)
    {
    }

    /**
     * @return array{
     *     event_count:int,
     *     paths:list<array{path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>
     * }
     */
    public function inspect(?int $now = null, int $windowSeconds = 86400, int $limit = 5): array
    {
        return $this->inspectWindows($now, ['result' => $windowSeconds], $limit)['result'];
    }

    /**
     * @return array{
     *     recent:array{event_count:int,paths:list<array{path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>},
     *     daily:array{event_count:int,paths:list<array{path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>}
     * }
     */
    public function inspectRecentAndDaily(?int $now = null, int $limit = 5): array
    {
        /** @var array{recent:array{event_count:int,paths:list<array{path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>},daily:array{event_count:int,paths:list<array{path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>}} $windows */
        $windows = $this->inspectWindows($now, [
            'recent' => self::RECENT_WINDOW_SECONDS,
            'daily'  => self::DAILY_WINDOW_SECONDS,
        ], $limit);

        return $windows;
    }

    /**
     * @param array<string, int> $windowSeconds
     * @return array<string, array{event_count:int,paths:list<array{path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>}>
     */
    private function inspectWindows(?int $now, array $windowSeconds, int $limit): array
    {
        /**
         * @var array<string, array{
         *     event_count:int,
         *     paths:array<string, array{path:string,count:int,total_ms:float,max_ms:float,db_ms:float}>
         * }> $aggregates
         */
        $aggregates = [];
        foreach (array_keys($windowSeconds) as $name) {
            $aggregates[$name] = ['event_count' => 0, 'paths' => []];
        }

        $now ??= time();
        $cutoffs = array_map(static fn(int $seconds): int => $now - $seconds, $windowSeconds);
        $windowsAreValid = $limit >= 1
            && array_filter($windowSeconds, static fn(int $seconds): bool => $seconds < 1) === [];
        $handle = $windowsAreValid && is_file($this->logFile) ? fopen($this->logFile, 'rb') : false;
        if ($handle !== false) {
            try {
                while (($line = fgets($handle)) !== false) {
                    try {
                        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        continue;
                    }

                    if (!\is_array($row) || !\is_string($row['at'] ?? null) || !\is_string($row['path'] ?? null)) {
                        continue;
                    }

                    $timestamp = strtotime($row['at']);
                    $duration = $this->number($row['duration_ms'] ?? null);
                    $database = $this->number($row['db_ms'] ?? null);
                    $slowestQuery = $this->number($row['db_slowest_ms'] ?? 0);
                    if ($timestamp === false || $duration === null || $database === null || $slowestQuery === null
                        || !RequestPerformanceMonitor::exceedsThresholds($duration, $database, $slowestQuery)
                    ) {
                        continue;
                    }

                    foreach ($cutoffs as $name => $cutoff) {
                        if ($timestamp < $cutoff) {
                            continue;
                        }

                        ++$aggregates[$name]['event_count'];
                        $path = $row['path'];
                        $aggregate = $aggregates[$name]['paths'][$path] ?? [
                            'path' => $path,
                            'count' => 0,
                            'total_ms' => 0.0,
                            'max_ms' => 0.0,
                            'db_ms' => 0.0,
                        ];
                        ++$aggregate['count'];
                        $aggregate['total_ms'] += $duration;
                        $aggregate['max_ms'] = max($aggregate['max_ms'], $duration);
                        $aggregate['db_ms'] += $database;
                        $aggregates[$name]['paths'][$path] = $aggregate;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        /** @var array<string, array{event_count:int,paths:list<array{path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>}> $summaries */
        $summaries = [];
        foreach ($aggregates as $name => $aggregate) {
            $paths = $aggregate['paths'];
            usort($paths, static fn(array $left, array $right): int => $right['total_ms'] <=> $left['total_ms']);
            $result = [];
            foreach (array_slice($paths, 0, $limit) as $path) {
                $path['average_ms'] = round($path['total_ms'] / (float)$path['count'], 1);
                $path['total_ms'] = round($path['total_ms'], 1);
                $path['max_ms'] = round($path['max_ms'], 1);
                $path['db_ms'] = round($path['db_ms'], 1);
                $result[] = $path;
            }

            $summaries[$name] = [
                'event_count' => $aggregate['event_count'],
                'paths'       => $result,
            ];
        }

        return $summaries;
    }

    private function number(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) ? (float)$value : null;
    }
}
