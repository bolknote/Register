<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use S2\Cms\HttpClient\HttpClient;

final readonly class WaybackClient implements WaybackClientInterface
{
    private const string ENDPOINT = 'https://archive.org/wayback/available';

    private const int MAX_RESPONSE_BYTES = 65_536;

    public function __construct(
        private LinkHttpClientInterface $httpClient,
        private PublicAddressGuard      $addressGuard,
    ) {
    }

    #[\Override]
    public function lookup(string $url, int $referenceTime): ArchiveLookupResult
    {
        if ($referenceTime < 0) {
            throw new \InvalidArgumentException('A Wayback reference time cannot be negative.');
        }

        $requestUrl = self::ENDPOINT . '?' . http_build_query([
            'url'       => $url,
            'timestamp' => gmdate('YmdHis', $referenceTime),
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->httpClient->request('GET', $requestUrl, ['Accept' => 'application/json'], [
            HttpClient::CONNECT_TIMEOUT    => 1,
            HttpClient::READ_TIMEOUT       => 2,
            HttpClient::FOLLOW_REDIRECTS   => false,
            HttpClient::RESOLVE_IP         => $this->addressGuard->resolvePublicAddress(self::ENDPOINT),
            HttpClient::MAX_RESPONSE_BYTES => self::MAX_RESPONSE_BYTES,
        ]);
        if (!$response->isSuccessful() || !\is_string($response->content)) {
            throw new \RuntimeException('The Wayback Availability API request failed with HTTP ' . $response->statusCode . '.');
        }

        try {
            $payload = json_decode($response->content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \RuntimeException('The Wayback Availability API returned invalid JSON.', 0, $jsonException);
        }

        if (!\is_array($payload)) {
            throw new \RuntimeException('The Wayback Availability API returned an invalid payload.');
        }

        $closest = $payload['archived_snapshots']['closest'] ?? null;
        if (!\is_array($closest) || ($closest['available'] ?? false) !== true) {
            return ArchiveLookupResult::missing();
        }

        $status    = $closest['status'] ?? null;
        $replayUrl = $closest['url'] ?? null;
        $timestamp = $closest['timestamp'] ?? null;
        if ((string)$status !== '200' || !\is_string($replayUrl) || !\is_string($timestamp)) {
            return ArchiveLookupResult::missing();
        }

        return ArchiveLookupResult::available($this->validateReplayUrl($replayUrl), $timestamp);
    }

    private function validateReplayUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!\is_array($parsed)
            || !isset($parsed['scheme'], $parsed['host'], $parsed['path'])
        ) {
            throw new \RuntimeException('The Wayback Availability API returned an unsafe replay URL.');
        }

        $scheme = strtolower($parsed['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)
            || strtolower(trim($parsed['host'], '[]')) !== 'web.archive.org'
            || !str_starts_with($parsed['path'], '/web/')
            || isset($parsed['user'])
            || isset($parsed['pass'])
            || isset($parsed['port'])
            || isset($parsed['fragment'])
        ) {
            throw new \RuntimeException('The Wayback Availability API returned an unsafe replay URL.');
        }

        return $scheme === 'http'
            ? 'https' . substr($url, \strlen('http'))
            : $url;
    }
}
