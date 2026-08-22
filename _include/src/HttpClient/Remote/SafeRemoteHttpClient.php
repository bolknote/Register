<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\HttpClient\Remote;

use S2\Cms\HttpClient\HttpClient;
use S2\Cms\HttpClient\HttpClientInterface;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueTimeBudgetExceeded;

/**
 * Executes exactly one validated HTTP hop.
 *
 * A redirect is normalized and returned, never followed. Calling code can persist its cursor and
 * pass the target back through this boundary, which resolves and pins every hop independently.
 */
final readonly class SafeRemoteHttpClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private PublicAddressGuard  $addressGuard,
    ) {
    }

    /** @param array<string, string> $headers */
    public function requestHop(
        string                    $method,
        string                    $url,
        array                     $headers = [],
        ?string                   $body = null,
        ?SafeRemoteRequestOptions $options = null,
        ?QueueExecutionBudget     $budget = null,
    ): SafeRemoteResponse {
        $options ??= new SafeRemoteRequestOptions();
        $this->validateScheme($url, $options->requireHttps);

        $maximumSeconds = $options->connectTimeout + $options->readTimeout;
        $dnsTimeout     = $this->usableBudgetSeconds($budget, $options->deadlineSafetyMargin);
        $resolvedIp     = $this->addressGuard->resolvePublicAddress($url, $dnsTimeout);
        $usableMilliseconds = (int)floor(
            (float)($this->usableBudgetSeconds($budget, $options->deadlineSafetyMargin)
                ?? $maximumSeconds) * 1000.0,
        );
        $totalMilliseconds   = min(
            $maximumSeconds * 1000,
            $usableMilliseconds,
        );
        $connectMilliseconds = min($options->connectTimeout * 1000, $totalMilliseconds);
        $response = $this->httpClient->request($method, $url, $headers, $body, [
            HttpClient::CONNECT_TIMEOUT_MILLISECONDS => $connectMilliseconds,
            HttpClient::TOTAL_TIMEOUT_MILLISECONDS   => $totalMilliseconds,
            HttpClient::FOLLOW_REDIRECTS              => false,
            HttpClient::RESOLVE_IP                    => $resolvedIp,
            HttpClient::MAX_RESPONSE_BYTES            => $options->maxResponseBytes,
        ]);

        $redirectUrl = null;
        if ($response->statusCode >= 300 && $response->statusCode < 400) {
            $location = $response->getHeader('Location');
            if ($location !== null && $location !== '') {
                $redirectUrl = $this->httpClient->resolveRedirectUrl($location, $url);
                $this->validateScheme($redirectUrl, $options->requireHttps);
            }
        }

        return new SafeRemoteResponse($url, $response, $redirectUrl);
    }

    private function usableBudgetSeconds(?QueueExecutionBudget $budget, float $safetyMargin): ?float
    {
        if (!$budget instanceof \S2\Cms\Queue\QueueExecutionBudget) {
            return null;
        }

        $usableSeconds = $budget->remainingSeconds() - $safetyMargin;
        if ($usableSeconds < 0.001) {
            throw new QueueTimeBudgetExceeded('There is no safe deadline left for a remote HTTP hop.');
        }

        return $usableSeconds;
    }

    private function validateScheme(string $url, bool $requireHttps): void
    {
        if (!$requireHttps) {
            return;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!\is_string($scheme) || strtolower($scheme) !== 'https') {
            throw new UnsafeRemoteAddress('A production remote request must use HTTPS.');
        }
    }
}
