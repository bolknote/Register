<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Content\ContentRepository;
use Register\Content\ContentType;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Core\Config\StringProxy;
use Register\Core\Controller\Rss\FeedDto;
use Register\Core\Controller\Rss\FeedItemDto;
use Register\Core\Controller\Rss\FeedSettings;
use Register\Core\Controller\Rss\RssStrategyInterface;
use Register\Core\Pdo\DbLayerException;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Publishes Register's canonical post stream as its single RSS feed. */
final readonly class ContentRssStrategy implements RssStrategyInterface
{
    public function __construct(
        private ContentRepository   $contentRepository,
        private ContentFeedItemProvider $itemProvider,
        private BlogUrlBuilder      $blogUrlBuilder,
        private TranslatorInterface $translator,
        private StringProxy         $blogTitle,
        private FeedSettings        $feedSettings,
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
            title: $blogTitle,
            description: \sprintf($this->translator->trans('RSS blog description'), $blogTitle),
            link: $this->blogUrlBuilder->absMain(),
            language: $this->translator->trans('locale'),
            rssLink: $this->blogUrlBuilder->absRss(),
            jsonFeedLink: $this->blogUrlBuilder->absJsonFeed(),
        );
    }

    /**
     * @throws DbLayerException
     * @return list<FeedItemDto>
     */
    #[\Override]
    public function getFeedItems(): array
    {
        return $this->itemProvider->provide(
            $this->contentRepository->recent(ContentType::POST, $this->feedSettings->itemLimit()),
        );
    }
}
