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
        if ($windowSeconds < 1 || $limit < 1 || !is_file($this->logFile)) {
            return ['event_count' => 0, 'paths' => []];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!\is_array($lines)) {
            return ['event_count' => 0, 'paths' => []];
        }

        $cutoff = ($now ?? time()) - $windowSeconds;
        $events = 0;
        $paths = [];
        foreach ($lines as $line) {
            try {
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!\is_array($row) || !\is_string($row['at'] ?? null) || !\is_string($row['path'] ?? null)) {
                continue;
            }
            $timestamp = strtotime($row['at']);
            if ($timestamp === false || $timestamp < $cutoff) {
                continue;
            }
            $duration = $this->number($row['duration_ms'] ?? null);
            $database = $this->number($row['db_ms'] ?? null);
            if ($duration === null || $database === null) {
                continue;
            }

            ++$events;
            $path = $row['path'];
            $aggregate = $paths[$path] ?? [
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
            $paths[$path] = $aggregate;
        }

        usort($paths, static fn(array $left, array $right): int => $right['total_ms'] <=> $left['total_ms']);
        $result = [];
        foreach (array_slice($paths, 0, $limit) as $aggregate) {
            $aggregate['average_ms'] = round($aggregate['total_ms'] / (float)$aggregate['count'], 1);
            $aggregate['total_ms'] = round($aggregate['total_ms'], 1);
            $aggregate['max_ms'] = round($aggregate['max_ms'], 1);
            $aggregate['db_ms'] = round($aggregate['db_ms'], 1);
            $result[] = $aggregate;
        }

        return ['event_count' => $events, 'paths' => $result];
    }

    private function number(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) ? (float)$value : null;
    }
}
