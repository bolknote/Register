<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

/** Builds bounded request and SQL-template views from the redacted profiler log. */
final readonly class QueryProfilerInspector
{
    public function __construct(private QueryProfilerLog $log)
    {
    }

    /**
     * @return array{
     *     request_count:int,
     *     query_count:int,
     *     contexts:list<array{
     *         client_group:string,
     *         agent:string,
     *         page_cache:string,
     *         cache_policy:string,
     *         query:string,
     *         cookies:string,
     *         purpose:string,
     *         fetch_mode:string,
     *         fetch_dest:string,
     *         count:int,
     *         query_count:int,
     *         average_queries:float,
     *         zero_query_percent:float,
     *         total_ms:float,
     *         db_ms:float
     *     }>,
     *     paths:list<array{method:string,path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>,
     *     templates:list<array{template:string,count:int,total_ms:float,average_ms:float,max_ms:float,path_count:int}>,
     *     recent:list<array{
     *         at:string,
     *         method:string,
     *         path:string,
     *         status:int,
     *         duration_ms:float,
     *         db_ms:float,
     *         query_count:int,
     *         truncated_queries:int,
     *         peak_memory_bytes:int,
     *         request_context:array{
     *             client_group:string,
     *             agent:string,
     *             page_cache:string,
     *             cache_policy:string,
     *             query:string,
     *             cookies:string,
     *             purpose:string,
     *             fetch_mode:string,
     *             fetch_dest:string
     *         },
     *         queries:list<array{template:string,time_ms:float}>
     *     }>
     * }
     */
    public function inspect(int $pathLimit = 15, int $templateLimit = 30, int $recentLimit = 100): array
    {
        if ($pathLimit < 1 || $templateLimit < 1 || $recentLimit < 1) {
            throw new \InvalidArgumentException('Query profiler report limits must be positive.');
        }

        $requestCount = 0;
        $queryCount = 0;
        $contexts = [];
        $paths = [];
        $templates = [];
        $recent = [];
        foreach ($this->log->lines() as $line) {
            try {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!\is_array($record)
                || !\is_string($record['at'] ?? null)
                || !\is_string($record['method'] ?? null)
                || !\is_string($record['path'] ?? null)
                || !\is_array($record['queries'] ?? null)
            ) {
                continue;
            }

            $duration = $this->number($record['duration_ms'] ?? null);
            $database = $this->number($record['db_ms'] ?? null);
            if ($duration === null || $database === null) {
                continue;
            }

            ++$requestCount;
            $recordQueryCount = \is_int($record['query_count'] ?? null) && $record['query_count'] >= 0
                ? $record['query_count']
                : count($record['queries']);
            $queryCount += $recordQueryCount;
            $requestContext = $this->requestContext($record['request_context'] ?? null);
            $contextKey = implode("\0", array_values($requestContext));
            $contextAggregate = $contexts[$contextKey] ?? [
                ...$requestContext,
                'count' => 0,
                'query_count' => 0,
                'zero_query_count' => 0,
                'total_ms' => 0.0,
                'db_ms' => 0.0,
            ];
            ++$contextAggregate['count'];
            $contextAggregate['query_count'] += $recordQueryCount;
            $contextAggregate['zero_query_count'] += (int)($recordQueryCount === 0);
            $contextAggregate['total_ms'] += $duration;
            $contextAggregate['db_ms'] += $database;
            $contexts[$contextKey] = $contextAggregate;

            $method = mb_substr($record['method'], 0, 12);
            $path = mb_substr($record['path'], 0, 500);
            $pathKey = $method . "\0" . $path;
            $pathAggregate = $paths[$pathKey] ?? [
                'method' => $method,
                'path' => $path,
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
                'db_ms' => 0.0,
            ];
            ++$pathAggregate['count'];
            $pathAggregate['total_ms'] += $duration;
            $pathAggregate['max_ms'] = max($pathAggregate['max_ms'], $duration);
            $pathAggregate['db_ms'] += $database;
            $paths[$pathKey] = $pathAggregate;

            $safeQueries = [];
            foreach ($record['queries'] as $query) {
                if (!\is_array($query) || !\is_string($query['template'] ?? null)) {
                    continue;
                }

                $time = $this->number($query['time_ms'] ?? null);
                if ($time === null) {
                    continue;
                }

                $template = mb_substr($query['template'], 0, 4000);
                $safeQueries[] = ['template' => $template, 'time_ms' => $time];
                $key = hash('sha256', $template);
                $aggregate = $templates[$key] ?? [
                    'template' => $template,
                    'count' => 0,
                    'total_ms' => 0.0,
                    'max_ms' => 0.0,
                    'paths' => [],
                ];
                ++$aggregate['count'];
                $aggregate['total_ms'] += $time;
                $aggregate['max_ms'] = max($aggregate['max_ms'], $time);
                $aggregate['paths'][$pathKey] = true;
                $templates[$key] = $aggregate;
            }

            $status = \is_int($record['status'] ?? null) && $record['status'] >= 100 && $record['status'] <= 599
                ? $record['status']
                : 0;
            $truncatedQueries = \is_int($record['truncated_queries'] ?? null) && $record['truncated_queries'] >= 0
                ? $record['truncated_queries']
                : 0;
            $peakMemory = \is_int($record['peak_memory_bytes'] ?? null) && $record['peak_memory_bytes'] >= 0
                ? $record['peak_memory_bytes']
                : 0;
            $recent[] = [
                'at'                => mb_substr($record['at'], 0, 64),
                'method'            => $method,
                'path'              => $path,
                'status'            => $status,
                'duration_ms'       => $duration,
                'db_ms'             => $database,
                'query_count'       => $recordQueryCount,
                'truncated_queries' => $truncatedQueries,
                'peak_memory_bytes' => $peakMemory,
                'request_context'   => $requestContext,
                'queries'           => $safeQueries,
            ];
        }

        usort($contexts, static fn(array $left, array $right): int => $right['total_ms'] <=> $left['total_ms']);
        $contextReport = [];
        foreach (array_slice($contexts, 0, 20) as $aggregate) {
            $contextReport[] = [
                'client_group'       => $aggregate['client_group'],
                'agent'              => $aggregate['agent'],
                'page_cache'         => $aggregate['page_cache'],
                'cache_policy'       => $aggregate['cache_policy'],
                'query'              => $aggregate['query'],
                'cookies'            => $aggregate['cookies'],
                'purpose'            => $aggregate['purpose'],
                'fetch_mode'         => $aggregate['fetch_mode'],
                'fetch_dest'         => $aggregate['fetch_dest'],
                'count'              => $aggregate['count'],
                'query_count'        => $aggregate['query_count'],
                'average_queries'    => round($aggregate['query_count'] / (float)$aggregate['count'], 2),
                'zero_query_percent' => round(100.0 * (float)$aggregate['zero_query_count'] / (float)$aggregate['count'], 1),
                'total_ms'           => round($aggregate['total_ms'], 1),
                'db_ms'              => round($aggregate['db_ms'], 1),
            ];
        }

        usort($paths, static fn(array $left, array $right): int => $right['total_ms'] <=> $left['total_ms']);
        $pathReport = [];
        foreach (array_slice($paths, 0, $pathLimit) as $aggregate) {
            $aggregate['average_ms'] = round($aggregate['total_ms'] / (float)$aggregate['count'], 1);
            $aggregate['total_ms'] = round($aggregate['total_ms'], 1);
            $aggregate['max_ms'] = round($aggregate['max_ms'], 1);
            $aggregate['db_ms'] = round($aggregate['db_ms'], 1);
            $pathReport[] = $aggregate;
        }

        usort($templates, static fn(array $left, array $right): int => $right['total_ms'] <=> $left['total_ms']);
        $templateReport = [];
        foreach (array_slice($templates, 0, $templateLimit) as $aggregate) {
            $templateReport[] = [
                'template' => $aggregate['template'],
                'count' => $aggregate['count'],
                'total_ms' => round($aggregate['total_ms'], 3),
                'average_ms' => round($aggregate['total_ms'] / (float)$aggregate['count'], 3),
                'max_ms' => round($aggregate['max_ms'], 3),
                'path_count' => count($aggregate['paths']),
            ];
        }

        return [
            'request_count' => $requestCount,
            'query_count' => $queryCount,
            'contexts' => $contextReport,
            'paths' => $pathReport,
            'templates' => $templateReport,
            'recent' => array_slice(array_reverse($recent), 0, $recentLimit),
        ];
    }

    private function number(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) ? (float)$value : null;
    }

    /**
     * @return array{
     *     client_group:string,
     *     agent:string,
     *     page_cache:string,
     *     cache_policy:string,
     *     query:string,
     *     cookies:string,
     *     purpose:string,
     *     fetch_mode:string,
     *     fetch_dest:string
     * }
     */
    private function requestContext(mixed $value): array
    {
        if (!\is_array($value)) {
            return array_fill_keys([
                'client_group',
                'agent',
                'page_cache',
                'cache_policy',
                'query',
                'cookies',
                'purpose',
                'fetch_mode',
                'fetch_dest',
            ], 'unknown');
        }

        return [
            'client_group' => $this->contextToken($value['client_group'] ?? null, 12),
            'agent'        => $this->contextToken($value['agent'] ?? null, 24),
            'page_cache'   => $this->contextToken($value['page_cache'] ?? null, 24),
            'cache_policy' => $this->contextToken($value['cache_policy'] ?? null, 24),
            'query'        => $this->contextToken($value['query'] ?? null, 24),
            'cookies'      => $this->contextToken($value['cookies'] ?? null, 24),
            'purpose'      => $this->contextToken($value['purpose'] ?? null, 24),
            'fetch_mode'   => $this->contextToken($value['fetch_mode'] ?? null, 24),
            'fetch_dest'   => $this->contextToken($value['fetch_dest'] ?? null, 24),
        ];
    }

    private function contextToken(mixed $value, int $maxLength): string
    {
        return \is_string($value) && preg_match('/^[a-z0-9_-]{1,' . $maxLength . '}$/D', $value) === 1
            ? $value
            : 'unknown';
    }
}
