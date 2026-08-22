<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Install;

use Register\Core\Config\SecretConfigPathResolver;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpClientException;
use Register\Core\HttpClient\HttpResponse;

final readonly class SecretFileBoundaryVerifier
{
    /** @var \Closure(string, array<string, bool|int|string>): HttpResponse */
    private \Closure $request;

    /** @param null|callable(string, array<string, bool|int|string>): HttpResponse $request */
    public function __construct(HttpClient $httpClient, ?callable $request = null)
    {
        $this->request = $request === null
            ? static fn(string $url, array $options): HttpResponse => $httpClient->request(
                'GET',
                $url,
                options: $options,
            )
            : \Closure::fromCallable($request);
    }

    public function verifyFallback(
        string $publicRoot,
        string $baseUrl,
        string $requestHost,
        string $serverAddress,
        int    $serverPort,
    ): bool {
        $targetHost = parse_url($baseUrl, PHP_URL_HOST);
        $targetPort = parse_url($baseUrl, PHP_URL_PORT);
        $targetScheme = parse_url($baseUrl, PHP_URL_SCHEME);
        if (!\is_string($targetHost)
            || !\is_string($targetScheme)
            || strcasecmp(trim($targetHost, '[]'), trim($requestHost, '[]')) !== 0
            || filter_var($serverAddress, FILTER_VALIDATE_IP) === false
        ) {
            return false;
        }

        $targetPort ??= strtolower($targetScheme) === 'https' ? 443 : 80;
        if (!\is_int($targetPort) || $targetPort !== $serverPort) {
            return false;
        }

        $publicRoot = realpath($publicRoot);
        if ($publicRoot === false || !is_dir($publicRoot) || is_link($publicRoot)) {
            return false;
        }

        $probeFile = $publicRoot . '/' . SecretConfigPathResolver::fallbackFilename();
        if (file_exists($probeFile) || is_link($probeFile)) {
            return false;
        }

        $canary = bin2hex(random_bytes(32));
        $content = "<?php\n\n// Register private-file boundary probe: {$canary}\n\nreturn [];\n";
        $handle = register_call_without_warnings(static fn() => fopen($probeFile, 'xb'));
        if ($handle === false) {
            return false;
        }

        try {
            if (DIRECTORY_SEPARATOR !== '\\' && !chmod($probeFile, 0600)) {
                return false;
            }

            $written = fwrite($handle, $content);
            if ($written !== \strlen($content) || !fflush($handle)) {
                return false;
            }

            fclose($handle);

            try {
                $response = ($this->request)(
                    rtrim($baseUrl, '/') . '/' . rawurlencode(SecretConfigPathResolver::fallbackFilename())
                        . '?boundary-probe=' . rawurlencode($canary),
                    [
                        HttpClient::CONNECT_TIMEOUT    => 5,
                        HttpClient::READ_TIMEOUT       => 5,
                        HttpClient::FOLLOW_REDIRECTS   => false,
                        HttpClient::RESOLVE_IP         => $serverAddress,
                        HttpClient::MAX_RESPONSE_BYTES => 65_536,
                    ],
                );
            } catch (HttpClientException) {
                return false;
            }

            if (\is_string($response->content) && str_contains($response->content, $canary)) {
                return false;
            }

            return $response->isSuccessful() || \in_array($response->statusCode, [403, 404, 410], true);
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }

            if (is_file($probeFile) && !unlink($probeFile)) {
                throw new \RuntimeException('Unable to remove the private-file boundary probe.');
            }
        }
    }
}
