<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use S2\Cms\HttpClient\HttpClient;
use S2\Cms\HttpClient\HttpResponse;

final readonly class LinkHttpClient implements LinkHttpClientInterface
{
    public function __construct(private HttpClient $httpClient)
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, int|bool|string> $options
     */
    #[\Override]
    public function request(string $method, string $url, array $headers, array $options): HttpResponse
    {
        /** @var array{connect_timeout?: positive-int, read_timeout?: positive-int, follow_redirects?: bool, resolve_ip?: non-empty-string, max_response_bytes?: positive-int} $options */
        return $this->httpClient->request($method, $url, $headers, options: $options);
    }

    #[\Override]
    public function resolveRedirectUrl(string $location, string $currentUrl): string
    {
        return $this->httpClient->resolveRedirectUrl($location, $currentUrl);
    }
}
