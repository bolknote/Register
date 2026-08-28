<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http;

use Register\Core\Http\Cache\PageCacheHeaders;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Reuses encoded bodies of deterministic page-cache responses. */
final readonly class ResponseCompressionCache
{
    public function __construct(
        private CacheInterface $cache,
        private bool           $disabled = false,
    ) {
    }

    /**
     * @param \Closure(string): (string|false) $compressor
     * @return array{content: string, cache_status: 'hit'|'miss'|null}|null
     */
    public function encode(
        Response $response,
        string $encoding,
        string $content,
        \Closure $compressor,
    ): ?array {
        $etag = $response->headers->get('ETag');
        $identity = $response->headers->get(PageCacheHeaders::IDENTITY);
        $cacheable = !$this->disabled
            && $response->getStatusCode() === Response::HTTP_OK
            && $response->headers->has(PageCacheHeaders::STATUS)
            && \is_string($etag)
            && $etag !== ''
            && \is_string($identity)
            && preg_match('/^[a-f0-9]{64}$/D', $identity) === 1
            && $response->headers->getCookies() === [];

        if (!$cacheable) {
            $compressed = $compressor($content);

            return \is_string($compressed)
                ? ['content' => $compressed, 'cache_status' => null]
                : null;
        }

        $signature = hash('sha256', $etag . "\0" . hash('sha256', $content));
        $key = 'register_encoded_response_v2_' . hash(
            'sha256',
            $encoding . "\0" . $identity,
        );
        $miss = false;
        $factory = static function (ItemInterface $_item, bool &$save) use (
            $compressor,
            $content,
            $signature,
            &$miss,
        ): array|false {
            $miss = true;
            $value = $compressor($content);
            if (!\is_string($value)) {
                $save = false;

                return false;
            }

            return ['signature' => $signature, 'content' => $value];
        };
        $cached = $this->cache->get(
            $key,
            $factory,
            0.0,
        );
        $encoded = $this->cachedContent($cached, $signature);
        if ($encoded === null) {
            $this->cache->delete($key);
            $cached = $this->cache->get($key, $factory, 0.0);
            $encoded = $this->cachedContent($cached, $signature);
        }

        return $encoded !== null
            ? ['content' => $encoded, 'cache_status' => $miss ? 'miss' : 'hit']
            : null;
    }

    private function cachedContent(mixed $cached, string $signature): ?string
    {
        if (!\is_array($cached)
            || ($cached['signature'] ?? null) !== $signature
            || !\is_string($cached['content'] ?? null)
        ) {
            return null;
        }

        return $cached['content'];
    }
}
