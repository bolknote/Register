<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Core\Controller\Rss\FeedDto;
use Register\Core\Controller\Rss\FeedItemDto;
use Register\Core\Controller\Rss\RssStrategyInterface;
use Register\Core\Model\UrlBuilder;
use Register\Module\Blog\Model\ContentFeedItemProvider;
use Register\Rose\Entity\Query;
use Register\Rose\Finder;
use Register\Rose\Storage\Exception\EmptyIndexException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Publishes the full-text result set selected by the current q parameter. */
final readonly class SearchRssStrategy implements RssStrategyInterface
{
    private const int ITEM_LIMIT = 20;

    public function __construct(
        private RequestStack            $requestStack,
        private Finder                  $finder,
        private ContentRepository       $contentRepository,
        private ContentFeedItemProvider $itemProvider,
        private UrlBuilder              $urlBuilder,
        private TranslatorInterface     $translator,
    ) {
    }

    #[\Override]
    public function getId(): string
    {
        return 'search';
    }

    #[\Override]
    public function getFeedInfo(): FeedDto
    {
        $query = $this->query();

        return new FeedDto(
            \sprintf($this->translator->trans('Search feed title'), $query),
            \sprintf($this->translator->trans('Search feed description'), $query),
            $this->urlBuilder->rawAbsLink('/search', $query === '' ? [] : ['q=' . rawurlencode($query)]),
        );
    }

    /** @return list<FeedItemDto> */
    #[\Override]
    public function getFeedItems(): array
    {
        $query = $this->query();
        if ($query === '') {
            return [];
        }

        $searchQuery = (new Query($query))->setLimit(self::ITEM_LIMIT);
        try {
            $results = $this->finder->find($searchQuery);
        } catch (EmptyIndexException) {
            return [];
        }

        $items = [];
        foreach ($results->getItems() as $result) {
            try {
                $contentId = ContentId::fromString($result->getId());
            } catch (\InvalidArgumentException) {
                continue;
            }

            $item = $this->contentRepository->find($contentId);
            if ($item instanceof ContentItem) {
                $items[] = $item;
            }
        }

        return $this->itemProvider->provide($items);
    }

    private function query(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request === null ? '' : trim($request->query->getString('q'));
    }
}
