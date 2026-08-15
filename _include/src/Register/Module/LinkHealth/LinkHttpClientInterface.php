<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use S2\Cms\HttpClient\HttpResponse;

interface LinkHttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, int|bool|string> $options
     */
    public function request(string $method, string $url, array $headers, array $options): HttpResponse;

    public function resolveRedirectUrl(string $location, string $currentUrl): string;
}
