<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

use Register\Core\Pdo\PDO;

/** Records slow dynamic requests without storing query strings, clients or SQL text. */
final readonly class RequestPerformanceMonitor
{
    public const float SLOW_REQUEST_MILLISECONDS = 1_000.0;

    public const float SLOW_DATABASE_MILLISECONDS = 500.0;

    public const float SLOWEST_QUERY_MILLISECONDS = 250.0;

    private const int MAX_LOG_BYTES = 5_000_000;

    public function __construct(
        private \PDO   $pdo,
        private string $logFile,
        private float  $requestStartedAt,
    ) {
    }

    /** @param array<string, mixed>|null $server */
    public function record(?array $server = null, ?int $statusCode = null, ?float $finishedAt = null): void
    {
        $finishedAt ??= microtime(true);
        $duration = max(0.0, $finishedAt - $this->requestStartedAt);
        $metrics = $this->pdo instanceof PDO
            ? $this->pdo->getQueryMetrics()
            : ['count' => 0, 'total_seconds' => 0.0, 'slowest_seconds' => 0.0];

        if (!self::exceedsThresholds(
            $duration * 1000.0,
            $metrics['total_seconds'] * 1000.0,
            $metrics['slowest_seconds'] * 1000.0,
        )) {
            return;
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

        try {
            $line = json_encode([
                'at' => gmdate(DATE_ATOM, (int)$finishedAt),
                'method' => $method,
                'path' => mb_substr($path, 0, 500),
                'status' => $statusCode,
                'duration_ms' => round($duration * 1000.0, 1),
                'db_queries' => $metrics['count'],
                'db_ms' => round($metrics['total_seconds'] * 1000.0, 1),
                'db_slowest_ms' => round($metrics['slowest_seconds'] * 1000.0, 1),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->append($line . "\n");
        } catch (\Throwable) {
            // Performance telemetry must never affect a response.
        }
    }

    public static function exceedsThresholds(float $durationMs, float $databaseMs, float $slowestQueryMs): bool
    {
        return $durationMs >= self::SLOW_REQUEST_MILLISECONDS
            || $databaseMs >= self::SLOW_DATABASE_MILLISECONDS
            || $slowestQueryMs >= self::SLOWEST_QUERY_MILLISECONDS;
    }

    private function append(string $line): void
    {
        $directory = dirname($this->logFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }

        $handle = fopen($this->logFile, 'c+b');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            fseek($handle, 0, SEEK_END);
            $size = ftell($handle);
            if (\is_int($size) && $size >= self::MAX_LOG_BYTES) {
                ftruncate($handle, 0);
                rewind($handle);
            }

            fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
            register_call_without_warnings(fn(): bool => chmod($this->logFile, 0600));
        } finally {
            fclose($handle);
        }
    }
}
