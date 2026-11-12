<?php
/**
 * @copyright 2026 Register contributors
 * @license https://opensource.org/license/mit MIT
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

/** A deliberately narrow query shape usable before the router or Composer is loaded. */
final readonly class PublicPageCacheRequest
{
    public const string ATTRIBUTE = '_register_public_pagination_cache';

    private function __construct(private string $path, private string $page)
    {
    }

    public static function fromUri(string $uri): ?self
    {
        if (strlen($uri) > 2048 || preg_match('~^(/[^?]*)\?p=([1-9][0-9]{0,8})$~D', $uri, $match) !== 1) {
            return null;
        }

        return new self($match[1], $match[2]);
    }

    /**
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    public function cacheServer(array $server): array
    {
        $server['REQUEST_URI'] = $this->path;
        $server['REGISTER_EARLY_VARIANT'] = ($server['REGISTER_EARLY_VARIANT'] ?? 'reader') . ':page:' . $this->page;
        return $server;
    }
}
