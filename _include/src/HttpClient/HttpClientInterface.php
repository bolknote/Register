<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\HttpClient;

/**
 * @phpstan-type RequestOptions array{connect_timeout?: int, read_timeout?: int, connect_timeout_ms?: int, total_timeout_ms?: int, follow_redirects?: bool, resolve_ip?: string, max_response_bytes?: int}
 * @psalm-type RequestOptions = array{connect_timeout?: int, read_timeout?: int, connect_timeout_ms?: int, total_timeout_ms?: int, follow_redirects?: bool, resolve_ip?: string, max_response_bytes?: int}
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param RequestOptions $options
     */
    public function request(
        string  $method,
        string  $url,
        array   $headers = [],
        ?string $body = null,
        array   $options = [],
    ): HttpResponse;

    public function resolveRedirectUrl(string $location, string $currentUrl): string;
}
