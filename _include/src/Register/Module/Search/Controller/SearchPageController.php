<?php
/**
 * Displays a page with search results
 *
 * @copyright 2010-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Controller;

use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Module\Search\Module;
use Register\Module\Search\Service\HistoricalTitleSearch;
use Register\Core\Config\IntProxy;
use Register\Core\Config\StringProxy;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Helper\StringHelper;
use Register\Core\Image\ThumbnailGenerator;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\Query;
use Register\Rose\Finder;
use Register\Rose\Helper\ProfileHelper;
use Register\Rose\Stemmer\StemmerHelper;
use Register\Rose\Stemmer\StemmerInterface;
use Register\Rose\Storage\Exception\EmptyIndexException;
use Register\Module\Search\Event\TagsSearchEvent;
use Register\Module\Search\Service\SimilarWordsDetector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Register\Core\Pdo\DbLayerException;
use Register\Rose\Exception\ImmutableException;
use Register\Rose\Exception\RuntimeException;
use Register\Rose\Exception\UnknownIdException;
use Register\Rose\Storage\Exception\InvalidEnvironmentException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

readonly class SearchPageController implements ControllerInterface
{
    public function __construct(
        private Finder                   $finder,
        private StemmerInterface         $stemmer,
        private HistoricalTitleSearch    $historicalTitleSearch,
        private ThumbnailGenerator       $thumbnailGenerator,
        private SimilarWordsDetector     $similarWordsDetector,
        private TagRepository            $tagRepository,
        private ArticleProvider          $articleProvider,
        private EventDispatcherInterface $eventDispatcher,
        private TranslatorInterface      $translator,
        private UrlBuilder               $urlBuilder,
        private HtmlTemplateProvider     $templateProvider,
        private Viewer                   $viewer,
        private bool                     $debugView,
        private StringProxy              $tagsUrl,
        private IntProxy                 $maxItems,
    ) {
    }

    /**
     * @throws DbLayerException
     * @throws ImmutableException
     * @throws RuntimeException
     * @throws UnknownIdException
     * @throws BadRequestException
     * @throws \JsonException
     */
    #[\Override]
    public function handle(Request $request): Response
    {
        if ($request->query->has('title')) {
            return $this->searchByTitle($request->query->getString('title'));
        }

        $query   = $request->query->getString('q');
        $pageNum = $request->query->getInt('p', 1);
        $content = ['query' => $query];

        $template = $this->templateProvider->getTemplate('service.php');

        if ($query !== '') {
            $items_per_page = $this->maxItems->get();
            if ($items_per_page <= 0) {
                $items_per_page = 10;
            }

            $queryObj       = new Query($query);
            $queryObj
                ->setLimit($items_per_page)
                ->setOffset(($pageNum - 1) * $items_per_page) // TODO Может быть за пределами
            ;
            $resultSet = null;
            try {
                $resultSet = $this->finder->find($queryObj, $this->debugView);
                $content   += ['num' => $resultSet->getTotalCount()];
            } catch (\Throwable $exception) {
                if (!$exception instanceof EmptyIndexException) {
                    throw $exception;
                }

                $content += ['num' => 0,];
            }

            $content += ['tags' => $this->findInTags($queryObj)];

            if ($content['num'] > 0 && $resultSet instanceof \Register\Rose\Entity\ResultSet) {
                $content['num_info'] = $this->translator->trans('Found N pages', ['%count%' => $content['num'], '{{ pages }}' => $content['num']]);

                $totalPages = intdiv($content['num'] + $items_per_page - 1, $items_per_page);
                if ($pageNum < 1 || $pageNum > $totalPages) {
                    $pageNum = 1;
                }

                $content['profile'] = array_map(ProfileHelper::formatProfilePoint(...), $resultSet->getProfilePoints());
                $content['trace']   = $resultSet->getTrace();

                $content['output'] = '';
                foreach ($resultSet->getItems() as $item) {
                    $content['output'] .= $this->viewer->render('search_result', [
                        'plainTitle'    => $item->getTitle(),
                        'title'         => $item->getHighlightedTitle($this->stemmer),
                        'link'          => $this->urlBuilder->link($item->getUrl()),
                        'descr'         => $item->getFormattedSnippet(),
                        'time'          => $item->getDate()?->getTimestamp(),
                        'images'        => $item->getImageCollection(),
                        'debug'         => $content['trace'][(new ExternalId($item->getId()))->toString()],
                        'thumbnailHtml' => $this->thumbnailGenerator->getThumbnailHtml(...),
                    ], Module::class);
                }

                $link_nav          = [];
                $content['paging'] = StringHelper::paging($pageNum, $totalPages, $this->urlBuilder->link('/search', ['q=' . str_replace('%', '%%', urlencode($query)), 'p=%d']), $link_nav);
                foreach ($link_nav as $rel => $href) {
                    $template->setLink($rel, $href);
                }
            }
        }

        $content['action'] = $this->urlBuilder->link('/search');
        $content['quickSearchUrl'] = $this->urlBuilder->rawLink(
            '/search',
            $this->urlBuilder->hasPrefix() ? ['search=1', 'title='] : ['title='],
        );

        $template->putInPlaceholder('text', $this->viewer->render('search', $content, Module::class));
        $template->putInPlaceholder('title', $this->translator->trans('Search'));
        $template->registerPlaceholder('<!-- register_search_field -->', '');

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));
        $template->addBreadCrumb($this->translator->trans('Search'));

        return $template->toHttpResponse();
    }

    /**
     * @throws DbLayerException
     */
    private function findInTags(Query $query): string
    {
        $words = $query->valueToArray();
        if (\count($words) === 0) {
            return '';
        }

        $normalizedWords = [];
        foreach ($words as $word) {
            array_push($normalizedWords, ...StemmerHelper::stemWords($this->stemmer, $word));
        }

        $words = array_unique(array_merge($words, $normalizedWords));

        $tags = [];
        foreach ($this->tagRepository->findPublishedUsage(ContentType::PAGE) as $usage) {
            $tag = $usage->tag;
            if ($this->similarWordsDetector->wordIsSimilarToOtherWords($tag->name, $words)) {
                $tags[] = '<a href="' . $this->urlBuilder->link('/' . rawurlencode($this->tagsUrl->get()) . '/' . rawurlencode($tag->slug) . '/') . '">' . register_htmlencode($tag->name) . '</a>';
            }
        }

        $event = new TagsSearchEvent($words);
        if (\count($tags) > 0) {
            $event->addLine(\sprintf($this->translator->trans('Found tags'), implode(', ', $tags)));
        }

        $this->eventDispatcher->dispatch($event);

        $string = $event->getLine();
        if ($string !== null) {
            return '<p class="register_search_found_tags">' . $string . '</p>';
        }

        return '';
    }

    /**
     * @throws InvalidEnvironmentException
     * @throws \JsonException
     */
    private function searchByTitle(string $titleQuery): Response
    {
        $result = '';
        foreach ($this->historicalTitleSearch->find($titleQuery) as $tocEntryWithExtId) {
            $highlightedTitle = $this->historicalTitleSearch->highlight(
                $tocEntryWithExtId->getTocEntry()->getTitle(),
                $titleQuery,
            );
            $result .= '<a href="' . $this->urlBuilder->link($tocEntryWithExtId->getTocEntry()->getUrl()) . '">' . $highlightedTitle . '</a>';
        }

        return new Response($result);
    }

}
