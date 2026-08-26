<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentType;
use Register\Content\Tag;
use Register\Content\TagRepository;
use Register\Core\Config\StringProxy;
use Register\Core\Controller\Rss\FeedDto;
use Register\Core\Controller\Rss\FeedItemDto;
use Register\Core\Controller\Rss\FeedSettings;
use Register\Core\Controller\Rss\RssStrategyInterface;
use Register\Core\Framework\Exception\NotFoundException;
use Register\Module\Blog\BlogUrlBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Publishes the posts attached to the tag in the current request. */
final readonly class TagRssStrategy implements RssStrategyInterface
{
    public function __construct(
        private RequestStack            $requestStack,
        private TagRepository           $tagRepository,
        private ContentRepository       $contentRepository,
        private ContentFeedItemProvider $itemProvider,
        private BlogUrlBuilder          $blogUrlBuilder,
        private TranslatorInterface     $translator,
        private StringProxy             $blogTitle,
        private FeedSettings            $feedSettings,
    ) {
    }

    #[\Override]
    public function getId(): string
    {
        return 'blog-tag';
    }

    #[\Override]
    public function getFeedInfo(): FeedDto
    {
        $tag = $this->currentTag();
        $title = \sprintf($this->translator->trans('Tag feed title'), $tag->name, $this->blogTitle->get());
        $description = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($tag->description), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($description === '') {
            $description = \sprintf($this->translator->trans('Tag feed description'), $tag->name);
        }

        return new FeedDto(
            title: $title,
            description: $description,
            link: $this->blogUrlBuilder->absTag($tag->slug),
            language: $this->translator->trans('locale'),
            rssLink: $this->blogUrlBuilder->absTagRss($tag->slug),
            jsonFeedLink: $this->blogUrlBuilder->absTagJsonFeed($tag->slug),
        );
    }

    /** @return list<FeedItemDto> */
    #[\Override]
    public function getFeedItems(): array
    {
        $ids = array_slice(
            $this->tagRepository->findPublishedContentIds($this->currentTag()->id, ContentType::POST),
            0,
            $this->feedSettings->itemLimit(),
        );
        $items = [];
        foreach ($ids as $id) {
            $item = $this->contentRepository->find($id);
            if ($item instanceof ContentItem) {
                $items[] = $item;
            }
        }

        return $this->itemProvider->provide($items);
    }

    private function currentTag(): Tag
    {
        $request = $this->requestStack->getCurrentRequest();
        $tag = $request === null ? null : $this->tagRepository->findBySlug($request->attributes->getString('tag'));
        if (!$tag instanceof Tag) {
            throw new NotFoundException();
        }

        return $tag;
    }
}
