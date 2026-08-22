<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Security;

/** Immutable transport view used identically by HTTP signature signers and verifiers. */
final readonly class HttpSignatureRequest
{
    /** @var array<string, string> */
    private array $normalizedHeaders;

    public string $method;

    public string $targetUri;

    public string $authority;

    public string $requestTarget;

    /** @param array<string, string> $headers */
    public function __construct(
        string        $method,
        string        $targetUri,
        array         $headers = [],
        public string $body = '',
    ) {
        $normalizedMethod = strtoupper($method);
        if (preg_match('/^[A-Z]+$/D', $normalizedMethod) !== 1) {
            throw new \InvalidArgumentException('The signed HTTP method is invalid.');
        }

        $parts = parse_url($targetUri);
        if (!\is_array($parts)
            || !\in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('The signed HTTP target URI is invalid.');
        }

        $scheme    = strtolower($parts['scheme']);
        $host      = strtolower($parts['host']);
        $port      = $parts['port'] ?? null;
        $authority = $host;
        if ($port !== null && (($scheme !== 'https' || $port !== 443) && ($scheme !== 'http' || $port !== 80))) {
            $authority .= ':' . $port;
        }

        $path          = $parts['path'] ?? '';
        $requestTarget = ($path === '' ? '/' : $path) . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $normalizedUri = $scheme . '://' . $authority . $requestTarget;

        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedName = strtolower($name);
            if (preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $normalizedName) !== 1
                || preg_match('/[\r\n]/', $value) === 1
                || isset($normalizedHeaders[$normalizedName])
            ) {
                throw new \InvalidArgumentException('A signed HTTP header is invalid or duplicated.');
            }

            $normalizedHeaders[$normalizedName] = trim($value, " \t");
        }

        $this->method            = $normalizedMethod;
        $this->targetUri         = $normalizedUri;
        $this->authority         = $authority;
        $this->requestTarget     = $requestTarget;
        $this->normalizedHeaders = $normalizedHeaders;
    }

    public function header(string $name): ?string
    {
        return $this->normalizedHeaders[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->normalizedHeaders;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->method,
            $this->targetUri,
            [...$this->normalizedHeaders, ...array_change_key_case($headers, CASE_LOWER)],
            $this->body,
        );
    }

    public function requiresBodyDigest(): bool
    {
        return !\in_array($this->method, ['GET', 'HEAD'], true) || $this->body !== '';
    }
}
