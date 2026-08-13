<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use Register\Content\ContentType;
use Register\Content\TagRepository;
use S2\Cms\Config\StringProxy;
use S2\Cms\Framework\StatefulServiceInterface;

class TagsProvider implements StatefulServiceInterface
{
    // Note: Add cache invalidation in case of daemon mode
    /**
     * @var array<mixed>|null
     */
    private ?array $cachedTags = null;

    public function __construct(
        private readonly TagRepository $tagRepository,
        private readonly UrlBuilder  $urlBuilder,
        private readonly StringProxy $tagsUrl
    ) {
    }

    /**
     * Makes tags list for the tags page and the placeholder
     *
     * @return array<mixed>
     */
    public function tagsList(): array
    {
        if ($this->cachedTags === null) {
            $this->cachedTags = [];
            foreach ($this->tagRepository->findPublishedUsage(ContentType::PAGE) as $usage) {
                $this->cachedTags[] = [
                    'title' => $usage->tag->name,
                    'link'  => $this->urlBuilder->link('/' . rawurlencode($this->tagsUrl->get()) . '/' . rawurlencode($usage->tag->slug) . '/'),
                    'num'   => $usage->publishedContentCount,
                ];
            }
        }

        return $this->cachedTags;
    }

    /**
     * @return array<mixed>
     */
    public function getAllTags(): array
    {
        return array_map(
            static fn(\Register\Content\TagUsage $usage): string => $usage->tag->name,
            $this->tagRepository->findAllUsage(ContentType::PAGE),
        );
    }

    #[\Override]
    public function clearState(): void
    {
        $this->cachedTags = null;
    }
}
