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
    private const int MAX_RESPONSE_BYTES = 16_384;

    private const int CONNECT_TIMEOUT = 1;

    private const int READ_TIMEOUT = 3;

    public function __construct(
        private LinkHttpClientInterface $httpClient,
        private PublicAddressGuard      $addressGuard,
    ) {
    }

    #[\Override]
    public function step(LinkProbeState $state): LinkProbeStep
    {
        try {
            $resolvedIp = $this->addressGuard->resolvePublicAddress($state->url);
            $response   = $this->request($state->method->value, $state->url, $resolvedIp);
            if ($state->method === LinkProbeMethod::HEAD && $response->statusCode >= 400) {
                return LinkProbeStep::pending(new LinkProbeState(
                    $state->url,
                    LinkProbeMethod::GET,
                    $state->redirects,
                ));
            }

            if ($response->statusCode < 300 || $response->statusCode >= 400) {
                return LinkProbeStep::complete(new LinkProbeResult($state->url, $response->statusCode));
            }

            $location = $response->getHeader('Location');
            if ($location === null || $location === '') {
                return LinkProbeStep::complete(new LinkProbeResult($state->url, $response->statusCode));
            }

            if ($state->redirects === LinkProbeState::MAX_REDIRECTS) {
                return LinkProbeStep::complete(new LinkProbeResult(
                    $state->url,
                    error: 'The link has too many redirects.',
                    errorReason: LinkProbeResult::ERROR_REDIRECT,
                ));
            }

            return LinkProbeStep::pending(new LinkProbeState(
                $this->httpClient->resolveRedirectUrl($location, $state->url),
                redirects: $state->redirects + 1,
            ));
        } catch (UnsafeRemoteAddress $exception) {
            return LinkProbeStep::complete(new LinkProbeResult(
                $state->url,
                error: $exception->getMessage(),
                errorReason: LinkProbeResult::ERROR_UNSAFE,
            ));
        } catch (RemoteHostResolutionFailed $exception) {
            return LinkProbeStep::complete(new LinkProbeResult(
                $state->url,
                error: $exception->getMessage(),
                errorReason: LinkProbeResult::ERROR_DNS,
            ));
        } catch (HttpClientException $exception) {
            return LinkProbeStep::complete(new LinkProbeResult(
                $state->url,
                error: $exception->getMessage(),
                errorReason: match ($exception->reason) {
                    HttpClientException::REASON_TIMEOUT => LinkProbeResult::ERROR_TIMEOUT,
                    HttpClientException::REASON_HOST_RESOLVE_FAILURE => LinkProbeResult::ERROR_DNS,
                    default => LinkProbeResult::ERROR_NETWORK,
                },
            ));
        }
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
