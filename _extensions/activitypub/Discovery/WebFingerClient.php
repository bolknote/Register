<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Discovery;

use Register\Core\HttpClient\Remote\SafeRemoteHttpClient;
use Register\Core\HttpClient\Remote\SafeRemoteRequestOptions;
use Register\Extension\activitypub\Domain\RemoteHandle;

/** Performs an explicitly requested, SSRF-safe WebFinger lookup with bounded redirects. */
final readonly class WebFingerClient
{
    private const int MAX_REDIRECTS = 3;

    private const int MAX_DOCUMENT_BYTES = 65_536;

    public function __construct(private SafeRemoteHttpClient $httpClient)
    {
    }

    public function discover(RemoteHandle $handle): WebFingerResult
    {
        $url   = $handle->webFingerUrl();
        $chain = [];
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; ++$redirects) {
            if (isset($chain[$url])) {
                throw new \DomainException('The remote WebFinger endpoint contains a redirect loop.');
            }

            $chain[$url] = true;
            $remote = $this->httpClient->requestHop(
                'GET',
                $url,
                ['Accept' => 'application/jrd+json, application/json; q=0.9'],
                options: new SafeRemoteRequestOptions(
                    connectTimeout: 2,
                    readTimeout: 3,
                    maxResponseBytes: self::MAX_DOCUMENT_BYTES,
                    requireHttps: true,
                ),
            );
            $status = $remote->response->statusCode;
            if ($status >= 200 && $status < 300) {
                $body = $remote->response->content;
                if (!\is_string($body)) {
                    throw new \DomainException('The remote WebFinger response has no body.');
                }

                return $this->parse($handle, $body);
            }

            if ($status >= 300 && $status < 400) {
                if ($remote->redirectUrl === null) {
                    throw new \DomainException('The remote WebFinger redirect has no usable Location.');
                }

                if ($redirects === self::MAX_REDIRECTS) {
                    throw new \DomainException('The remote WebFinger endpoint exceeded the redirect limit.');
                }

                $url = $remote->redirectUrl;
                continue;
            }

            if ($status === 404 || $status === 410) {
                throw new \DomainException('The ActivityPub handle was not found by WebFinger.');
            }

            throw new \DomainException('The remote WebFinger endpoint returned HTTP ' . $status . '.');
        }

        throw new \LogicException('The WebFinger redirect loop terminated unexpectedly.');
    }

    private function parse(RemoteHandle $handle, string $json): WebFingerResult
    {
        if ($json === '' || \strlen($json) > self::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('The WebFinger document is empty or too large.');
        }

        try {
            $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The WebFinger document is invalid JSON.', 0, $exception);
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new \InvalidArgumentException('The WebFinger document must be a JSON object.');
        }

        $subject = $this->value($document, 'subject');
        if (!\is_string($subject) || !hash_equals(strtolower($handle->accountUri()), strtolower($subject))) {
            throw new \DomainException('The WebFinger subject does not match the requested ActivityPub handle.');
        }

        $links = $document['links'] ?? null;
        if (!\is_array($links) || !array_is_list($links) || \count($links) > 64) {
            throw new \InvalidArgumentException('The WebFinger links list is missing or too large.');
        }

        $actorUrl = null;
        foreach ($links as $link) {
            if (!\is_array($link) || array_is_list($link) || ($link['rel'] ?? null) !== 'self') {
                continue;
            }

            $type = $link['type'] ?? '';
            if (!\is_string($type)
                || !\in_array(strtolower(trim(explode(';', $type, 2)[0])), [
                    'application/activity+json',
                    'application/ld+json',
                ], true)
            ) {
                continue;
            }

            $candidate = $link['href'] ?? null;
            if (\is_string($candidate)) {
                $actorUrl = $this->httpsUrl($candidate, 'actor');
                break;
            }
        }

        if ($actorUrl === null) {
            throw new \DomainException('WebFinger publishes no ActivityPub self link for this handle.');
        }

        $aliases = [];
        $values  = $document['aliases'] ?? [];
        if (!\is_array($values) || !array_is_list($values) || \count($values) > 32) {
            throw new \InvalidArgumentException('The WebFinger alias list is invalid or too large.');
        }

        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new \InvalidArgumentException('The WebFinger alias list contains a non-string value.');
            }

            $alias = $this->httpsUrl($value, 'alias');
            $aliases[$alias] = $alias;
        }

        return new WebFingerResult($handle, $actorUrl, array_values($aliases));
    }

    private function httpsUrl(string $url, string $field): string
    {
        $parts = parse_url($url);
        if (\strlen($url) > 2_048
            || !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new \InvalidArgumentException('The WebFinger ' . $field . ' URL must be bounded credential-free HTTPS.');
        }

        return $url;
    }

    /** @param array<mixed> $document */
    private function value(array $document, string $key): mixed
    {
        return $document[$key] ?? null;
    }
}
