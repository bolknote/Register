<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Pdo\PDO;

/** Captures every database query during a short, explicitly enabled administration session. */
final class RequestQueryProfiler implements StatefulServiceInterface
{
    private const int MAX_QUERIES_PER_REQUEST = 200;

    private bool $suppressed = false;

    public function __construct(
        private readonly \PDO                      $pdo,
        private readonly QueryProfilerState        $state,
        private readonly QueryProfilerLog          $log,
        private readonly SqlQueryTemplateSanitizer $sanitizer,
        private readonly float                     $requestStartedAt,
    ) {
    }

    public function suppress(): void
    {
        $this->suppressed = true;
    }

    /** @param array<string, mixed>|null $server */
    public function record(?array $server = null, ?int $statusCode = null, ?float $finishedAt = null): void
    {
        $finishedAt ??= microtime(true);
        try {
            if ($this->suppressed || !$this->state->isActive((int)$finishedAt) || !$this->pdo instanceof PDO) {
                return;
            }

            $queryLog = $this->pdo->getQueryLog();
            $queries = [];
            foreach (array_slice($queryLog, 0, self::MAX_QUERIES_PER_REQUEST) as $entry) {
                $queries[] = [
                    'template' => $this->sanitizer->sanitize($entry['template']),
                    'time_ms' => round($entry['time'] * 1000.0, 3),
                ];
            }

            $server ??= $_SERVER;
            $requestUri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
            $path = parse_url($requestUri, PHP_URL_PATH);
            if (!\is_string($path) || $path === '') {
                $path = '/';
            }

            $method = \is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET';
            $method = preg_match('/^[A-Z]{1,12}$/D', $method) === 1 ? $method : 'OTHER';
            $statusCode ??= http_response_code();
            if ($statusCode < 100 || $statusCode > 599) {
                $statusCode = 200;
            }

            $metrics = $this->pdo->getQueryMetrics();

            $this->log->append([
                'version' => 1,
                'at' => gmdate(DATE_ATOM, (int)$finishedAt),
                'method' => $method,
                'path' => mb_substr($path, 0, 500),
                'status' => $statusCode,
                'duration_ms' => round(max(0.0, $finishedAt - $this->requestStartedAt) * 1000.0, 1),
                'db_ms' => round($metrics['total_seconds'] * 1000.0, 3),
                'query_count' => $metrics['count'],
                'truncated_queries' => max(0, $metrics['count'] - count($queries)),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'queries' => $queries,
            ]);
        } catch (\Throwable) {
            // Profiling is diagnostic and must never affect a response.
        }
    }

    #[\Override]
    public function clearState(): void
    {
        $this->suppressed = false;
    }
}
