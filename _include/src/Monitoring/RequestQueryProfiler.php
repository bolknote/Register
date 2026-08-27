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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
    public function record(
        ?array $server = null,
        ?int $statusCode = null,
        ?float $finishedAt = null,
        ?Request $request = null,
        ?Response $response = null,
    ): void
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
            $requestContext = $this->requestContext($server, $request, $response, (int)$finishedAt);

            $this->log->append([
                'version' => 2,
                'at' => gmdate(DATE_ATOM, (int)$finishedAt),
                'method' => $method,
                'path' => mb_substr($path, 0, 500),
                'status' => $statusCode,
                'duration_ms' => round(max(0.0, $finishedAt - $this->requestStartedAt) * 1000.0, 1),
                'db_ms' => round($metrics['total_seconds'] * 1000.0, 3),
                'query_count' => $metrics['count'],
                'truncated_queries' => max(0, $metrics['count'] - count($queries)),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'request_context' => $requestContext,
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

    /**
     * @param array<string, mixed> $server
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
    private function requestContext(
        array $server,
        ?Request $request,
        ?Response $response,
        int $finishedAt,
    ): array {
        $userAgent = $request instanceof Request ? $request->headers->get('User-Agent') : null;
        if (!\is_string($userAgent)) {
            $userAgent = \is_string($server['HTTP_USER_AGENT'] ?? null) ? $server['HTTP_USER_AGENT'] : '';
        }

        $clientAddress = $request instanceof Request ? $request->getClientIp() : null;
        if (!\is_string($clientAddress)) {
            $clientAddress = \is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        }

        $purpose = strtolower(trim(implode(' ', [
            $this->header($request, $server, 'Purpose', 'HTTP_PURPOSE'),
            $this->header($request, $server, 'Sec-Purpose', 'HTTP_SEC_PURPOSE'),
        ])));
        $cacheStatus = strtolower((string)($response instanceof Response
            ? $response->headers->get('X-Register-Page-Cache', '')
            : ''));
        $hasRequestCookies = false;
        if ($request instanceof Request) {
            $hasRequestCookies = $request->cookies->count() > 0;
        }
        $requestUri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '';
        $hasQuery = $request instanceof Request
            ? $request->query->count() > 0
            : \is_string(parse_url($requestUri, PHP_URL_QUERY));
        $cachePolicy = $request instanceof Request
            ? $request->attributes->getString('_register_page_cache_policy')
            : '';

        return [
            'client_group' => $this->state->clientGroup($clientAddress, $userAgent, $finishedAt) ?? 'unknown',
            'agent' => $this->agentFamily($userAgent),
            'page_cache' => \in_array($cacheStatus, ['hit', 'miss'], true) ? $cacheStatus : 'none',
            'cache_policy' => $this->boundedToken($cachePolicy),
            'query' => $hasQuery ? 'present' : 'none',
            'cookies' => $hasRequestCookies
                || (\is_string($server['HTTP_COOKIE'] ?? null) && trim($server['HTTP_COOKIE']) !== '')
                ? 'present'
                : 'none',
            'purpose' => $purpose === '' ? 'none' : (str_contains($purpose, 'prefetch') ? 'prefetch' : 'other'),
            'fetch_mode' => $this->boundedToken($this->header($request, $server, 'Sec-Fetch-Mode', 'HTTP_SEC_FETCH_MODE')),
            'fetch_dest' => $this->boundedToken($this->header($request, $server, 'Sec-Fetch-Dest', 'HTTP_SEC_FETCH_DEST')),
        ];
    }

    /** @param array<string, mixed> $server */
    private function header(Request|null $request, array $server, string $header, string $serverKey): string
    {
        $value = $request instanceof Request ? $request->headers->get($header) : null;
        if (\is_string($value)) {
            return $value;
        }

        return \is_string($server[$serverKey] ?? null) ? $server[$serverKey] : '';
    }

    private function boundedToken(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return 'none';
        }

        return preg_match('/^[a-z0-9_-]{1,24}$/D', $value) === 1 ? $value : 'other';
    }

    private function agentFamily(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'none';
        }

        if (preg_match('/(?:bot|crawler|spider|slurp|archiver|headless|lighthouse)/i', $userAgent) === 1) {
            return 'automated';
        }

        return match (true) {
            str_contains($userAgent, 'OPR/') => 'opera',
            str_contains($userAgent, 'Edg/') => 'edge',
            str_contains($userAgent, 'Firefox/') => 'firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'chrome',
            str_contains($userAgent, 'Safari/') => 'safari',
            str_contains(strtolower($userAgent), 'curl/') => 'curl',
            str_contains(strtolower($userAgent), 'wget/') => 'wget',
            default => 'other',
        };
    }
}
