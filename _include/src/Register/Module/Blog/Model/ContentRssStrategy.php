<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentType;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\Module as BlogModule;
use Register\Url\ContentUrlGenerator;
use S2\Cms\Config\StringProxy;
use S2\Cms\Controller\Rss\FeedDto;
use S2\Cms\Controller\Rss\FeedItemDto;
use S2\Cms\Controller\Rss\RssStrategyInterface;
use S2\Cms\Pdo\DbLayerException;
use S2\Cms\Template\Viewer;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Publishes Register's canonical post stream as its single RSS feed. */
final readonly class ContentRssStrategy implements RssStrategyInterface
{
    private const int ITEM_LIMIT = 10;

    public function __construct(
        private ContentRepository   $contentRepository,
        private PostProvider        $postProvider,
        private BlogUrlBuilder      $blogUrlBuilder,
        private ContentUrlGenerator $contentUrlGenerator,
        private TranslatorInterface $translator,
        private Viewer              $viewer,
        private StringProxy         $blogTitle,
    ) {
    }

    #[\Override]
    public function getId(): string
    {
        return 'blog';
    }

    #[\Override]
    public function getFeedInfo(): FeedDto
    {
        $blogTitle = $this->blogTitle->get();

        return new FeedDto(
            $blogTitle,
            \sprintf($this->translator->trans('RSS blog description'), $blogTitle),
            $this->blogUrlBuilder->absMain(),
        );
    }

    /**
     * @throws DbLayerException
     * @return list<FeedItemDto>
     */
    #[\Override]
    public function getFeedItems(): array
    {
        $contentItems = iterator_to_array(
            $this->contentRepository->recent(ContentType::POST, self::ITEM_LIMIT),
            false,
        );
        $ids          = array_map(static fn(ContentItem $item): int => $item->id->value, $contentItems);
        $series       = [];
        foreach ($contentItems as $contentItem) {
            if ($contentItem->series !== '') {
                $series[$contentItem->series] = 1;
            }
        }

        $related = [];
        $tags    = [];
        $this->postProvider->postsLinks($ids, $series, $related, $tags);

        $feedItems = [];
        foreach ($contentItems as $contentItem) {
            $publishedAt = $contentItem->publishedAt ?? 0;
            $updatedAt   = max($contentItem->updatedAt ?? 0, $publishedAt);
            $feedItems[] = new FeedItemDto(
                $contentItem->title,
                $contentItem->author,
                $this->contentUrlGenerator->absolutePath($contentItem->path),
                $this->renderBody($contentItem, $related, $tags),
                $publishedAt,
                $updatedAt,
            );
        }

        return $feedItems;
    }

    /**
     * @param array<mixed> $related
     * @param array<mixed> $tags
     */
    private function renderBody(ContentItem $contentItem, array $related, array $tags): string
    {
        $postRelated = $contentItem->series === '' ? [] : ($related[$contentItem->series] ?? []);
        unset($postRelated[$contentItem->id->value]);
        $postTags    = $tags[$contentItem->id->value] ?? [];

        return $contentItem->body
            . ($postRelated === [] ? '' : $this->viewer->render('see_also', [
                'see_also' => $postRelated,
            ], BlogModule::class))
            . ($postTags === [] ? '' : $this->viewer->render('tags', [
                'title' => $this->translator->trans('Tags'),
                'tags'  => $postTags,
            ], BlogModule::class));
    }
}
