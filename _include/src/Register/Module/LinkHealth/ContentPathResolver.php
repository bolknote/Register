<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentId;
use Register\Content\ContentRepository;

final class ContentPathResolver
{
    /** @var array<string, ContentId|null>|null */
    private ?array $contentByPath = null;

    public function __construct(
        private readonly ContentRepository  $contentRepository,
        private readonly LinkUrlNormalizer $linkUrlNormalizer,
    ) {
    }

    public function resolve(string $path): ?ContentId
    {
        $this->contentByPath ??= $this->buildIndex();

        $path = $this->linkUrlNormalizer->canonicalLocalPath($path);
        if (\array_key_exists($path, $this->contentByPath)) {
            return $this->contentByPath[$path];
        }

        if ($path === '/') {
            return null;
        }

        $alternatePath = str_ends_with($path, '/')
            ? rtrim($path, '/')
            : $path . '/';

        return $this->contentByPath[$alternatePath] ?? null;
    }

    public function refresh(): void
    {
        $this->contentByPath = null;
    }

    /** @return array<string, ContentId|null> */
    private function buildIndex(): array
    {
        $index = [];
        foreach ($this->contentRepository->published() as $content) {
            $path = $this->linkUrlNormalizer->canonicalLocalPath($content->path);
            if (\array_key_exists($path, $index)) {
                $index[$path] = null;
                continue;
            }

            $index[$path] = $content->id;
        }

        return $index;
    }
}
