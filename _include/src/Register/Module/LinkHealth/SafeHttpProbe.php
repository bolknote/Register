<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use S2\Cms\HttpClient\HttpClient;
use S2\Cms\HttpClient\HttpClientException;

final readonly class SafeHttpProbe implements LinkProbeInterface
{
    private const int MAX_REDIRECTS = 5;

    private const int MAX_RESPONSE_BYTES = 16_384;

    private const int CONNECT_TIMEOUT = 3;

    private const int READ_TIMEOUT = 5;

    public function __construct(
        private LinkHttpClientInterface $httpClient,
        private PublicAddressGuard      $addressGuard,
    ) {
    }

    #[\Override]
    public function probe(string $url): LinkProbeResult
    {
        $currentUrl = $url;
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; ++$redirects) {
            try {
                $resolvedIp = $this->addressGuard->resolvePublicAddress($currentUrl);
                $response   = $this->request('HEAD', $currentUrl, $resolvedIp);
                if ($response->statusCode >= 400) {
                    $response = $this->request('GET', $currentUrl, $resolvedIp);
                }

                if ($response->statusCode < 300 || $response->statusCode >= 400) {
                    return new LinkProbeResult($currentUrl, $response->statusCode);
                }

                $location = $response->getHeader('Location');
                if ($location === null || $location === '') {
                    return new LinkProbeResult($currentUrl, $response->statusCode);
                }

                if ($redirects === self::MAX_REDIRECTS) {
                    return new LinkProbeResult(
                        $currentUrl,
                        error: 'The link has too many redirects.',
                        errorReason: LinkProbeResult::ERROR_REDIRECT,
                    );
                }

                $currentUrl = $this->httpClient->resolveRedirectUrl($location, $currentUrl);
            } catch (UnsafeRemoteAddress $exception) {
                return new LinkProbeResult(
                    $currentUrl,
                    error: $exception->getMessage(),
                    errorReason: LinkProbeResult::ERROR_UNSAFE,
                );
            } catch (RemoteHostResolutionFailed $exception) {
                return new LinkProbeResult(
                    $currentUrl,
                    error: $exception->getMessage(),
                    errorReason: LinkProbeResult::ERROR_DNS,
                );
            } catch (HttpClientException $exception) {
                return new LinkProbeResult(
                    $currentUrl,
                    error: $exception->getMessage(),
                    errorReason: match ($exception->reason) {
                        HttpClientException::REASON_TIMEOUT => LinkProbeResult::ERROR_TIMEOUT,
                        HttpClientException::REASON_HOST_RESOLVE_FAILURE => LinkProbeResult::ERROR_DNS,
                        default => LinkProbeResult::ERROR_NETWORK,
                    },
                );
            }
        }

        throw new \LogicException('The HTTP redirect loop terminated unexpectedly.');
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $url, string $resolvedIp, array $headers = []): \S2\Cms\HttpClient\HttpResponse
    {
        return $this->httpClient->request($method, $url, $headers, [
            HttpClient::CONNECT_TIMEOUT    => self::CONNECT_TIMEOUT,
            HttpClient::READ_TIMEOUT       => self::READ_TIMEOUT,
            HttpClient::FOLLOW_REDIRECTS   => false,
            HttpClient::RESOLVE_IP         => $resolvedIp,
            HttpClient::MAX_RESPONSE_BYTES => self::MAX_RESPONSE_BYTES,
        ]);
    }
}
