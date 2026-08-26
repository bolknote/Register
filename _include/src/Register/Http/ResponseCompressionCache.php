<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Reuses encoded bodies of deterministic page-cache responses. */
final readonly class ResponseCompressionCache
{
    private const int TTL_SECONDS = 3600;

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
        $cacheable = !$this->disabled
            && $response->getStatusCode() === Response::HTTP_OK
            && $response->headers->has('X-Register-Page-Cache')
            && \is_string($etag)
            && $etag !== ''
            && $response->headers->getCookies() === [];

        if (!$cacheable) {
            $compressed = $compressor($content);

            return \is_string($compressed)
                ? ['content' => $compressed, 'cache_status' => null]
                : null;
        }

        $key = 'register_encoded_response_v1_' . hash(
            'sha256',
            $encoding . "\0" . $etag . "\0" . hash('sha256', $content),
        );
        $miss = false;
        $compressed = $this->cache->get(
            $key,
            static function (ItemInterface $item, bool &$save) use ($compressor, $content, &$miss): string|false {
                $miss = true;
                $item->expiresAfter(self::TTL_SECONDS);
                $value = $compressor($content);
                if (!\is_string($value)) {
                    $save = false;
                }

                return $value;
            },
            0.0,
        );

        return \is_string($compressed)
            ? ['content' => $compressed, 'cache_status' => $miss ? 'miss' : 'hit']
            : null;
    }
}
