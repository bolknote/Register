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
use Register\Core\Controller\Rss\RssStrategyInterface;
use Register\Core\Pdo\DbLayerException;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Publishes Register's canonical post stream as its single RSS feed. */
final readonly class ContentRssStrategy implements RssStrategyInterface
{
    private const int ITEM_LIMIT = 10;

    public function __construct(
        private ContentRepository   $contentRepository,
        private ContentFeedItemProvider $itemProvider,
        private BlogUrlBuilder      $blogUrlBuilder,
        private TranslatorInterface $translator,
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
        return $this->itemProvider->provide(
            $this->contentRepository->recent(ContentType::POST, self::ITEM_LIMIT),
        );
    }
}
