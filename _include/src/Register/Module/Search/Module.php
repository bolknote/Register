<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Register\Content\ContentId;
use Register\Content\ContentChangedEvent;
use Register\Content\ContentRepository;
use S2\Cms\Asset\AssetPack;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Framework\RoutingModuleInterface;
use S2\Cms\Image\ThumbnailGenerateEvent;
use S2\Cms\Image\ThumbnailGenerator;
use S2\Cms\Logger\Logger;
use S2\Cms\Model\Article\ArticleRenderedEvent;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Queue\ScheduledMaintenanceTaskInterface;
use S2\Cms\Template\HtmlTemplateProvider;
use S2\Cms\Template\TemplateAssetEvent;
use S2\Cms\Template\TemplateEvent;
use S2\Cms\Template\Viewer;
use S2\Rose\Entity\ExternalId;
use S2\Rose\Extractor\ExtractorInterface;
use S2\Rose\Finder;
use S2\Rose\Indexer;
use S2\Rose\Stemmer\PorterStemmerEnglish;
use S2\Rose\Stemmer\PorterStemmerRussian;
use S2\Rose\Stemmer\StemmerInterface;
use S2\Rose\Stemmer\WordNormalizerInterface;
use S2\Rose\Storage\Database\PdoStorage;
use Register\Module\Search\Controller\SearchPageController;
use Register\Module\Search\Admin\SearchIndexHealth;
use Register\Module\Search\Layout\LayoutMatcherFactory;
use Register\Module\Search\Morphology\ChurchSlavonicNormalizer;
use Register\Module\Search\Morphology\HistoricalRussianNormalizer;
use Register\Module\Search\Morphology\HybridWordNormalizer;
use Register\Module\Search\Morphology\OpenCorporaDictionary;
use Register\Module\Search\Morphology\PreReformRussianNormalizer;
use Register\Module\Search\Rose\CustomExtractor;
use Register\Module\Search\Service\BulkIndexingProviderInterface;
use Register\Module\Search\Service\ContentIndexer;
use Register\Module\Search\Service\HistoricalTitleSearch;
use Register\Module\Search\Service\RecommendationFinder;
use Register\Module\Search\Service\RecommendationProvider;
use Register\Module\Search\Service\SearchDocumentFactory;
use Register\Module\Search\Service\SearchIndexMaintenance;
use Register\Module\Search\Service\SearchIndexRepairer;
use Register\Module\Search\Service\SimilarWordsDetector;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use S2\Cms\Translation\ExtensibleTranslator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class Module implements ContainerModuleInterface, ContainerAwareListenerModuleInterface, RoutingModuleInterface
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function createPdoStorage(Container $container): PdoStorage
    {
        return new PdoStorage(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix') . 's2_search_idx_'
        );
    }

    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(PdoStorage::class, self::createPdoStorage(...));
        $container->set(OpenCorporaDictionary::class, static fn(): OpenCorporaDictionary => new OpenCorporaDictionary(
            __DIR__ . '/resources/morphology/ru',
        ));
        $container->set(PreReformRussianNormalizer::class, new PreReformRussianNormalizer());
        $container->set(ChurchSlavonicNormalizer::class, new ChurchSlavonicNormalizer());
        $container->set(HistoricalRussianNormalizer::class, static fn(Container $container): HistoricalRussianNormalizer => new HistoricalRussianNormalizer(
            $container->get(ChurchSlavonicNormalizer::class),
            $container->get(PreReformRussianNormalizer::class),
            $container->get(OpenCorporaDictionary::class),
        ));
        $container->set(WordNormalizerInterface::class, static fn(Container $container): HybridWordNormalizer => new HybridWordNormalizer(
            $container->get(HistoricalRussianNormalizer::class),
            new PorterStemmerRussian(new PorterStemmerEnglish()),
        ));
        $container->set(StemmerInterface::class, static fn(Container $container): WordNormalizerInterface => $container->get(WordNormalizerInterface::class));
        $container->set(Finder::class, fn(Container $container): \S2\Rose\Finder => (new Finder(
            $container->get(PdoStorage::class),
            $container->get(StemmerInterface::class),
        ))
            ->setHighlightTemplate('<span class="s2_search_highlight">%s</span>')
            ->setSnippetLineSeparator(' ⋄&nbsp;'));

        // Indexing is performed in the queue consumer, so this service belongs to the public module.
        $container->set(Indexer::class, static fn(Container $container): \S2\Rose\Indexer => new Indexer(
            $container->get(PdoStorage::class),
            $container->get(StemmerInterface::class),
            $container->get(ExtractorInterface::class),
            $container->get(LoggerInterface::class),
        ));

        $container->set(SearchDocumentFactory::class, new SearchDocumentFactory());
        $container->set(ContentIndexer::class, static fn(Container $container): ContentIndexer => new ContentIndexer(
            $container->get(ContentRepository::class),
            $container->get(SearchDocumentFactory::class),
            $container->get(Indexer::class),
            $container->get('recommendations_cache'),
            $container->get(QueuePublisher::class),
        ), [QueueHandlerInterface::class, BulkIndexingProviderInterface::class]);

        $container->set(SearchIndexHealth::class, static fn(Container $container): SearchIndexHealth => new SearchIndexHealth(
            $container->get(PdoStorage::class),
            $container->get(\S2\Cms\Pdo\DbLayer::class),
            $container->get(ContentIndexer::class),
        ));

        $container->set(SearchIndexRepairer::class, static fn(Container $container): SearchIndexRepairer => new SearchIndexRepairer(
            $container->get(ContentRepository::class),
            $container->get(PdoStorage::class),
            $container->get(Indexer::class),
            $container->get('recommendations_cache'),
            $container->get(QueuePublisher::class),
        ), [QueueHandlerInterface::class]);

        $container->set(SearchIndexMaintenance::class, static fn(Container $container): SearchIndexMaintenance => new SearchIndexMaintenance(
            $container->get(SearchIndexHealth::class),
            $container->get(SearchIndexRepairer::class),
        ), [ScheduledMaintenanceTaskInterface::class]);

        $container->set(SearchIndexRebuilder::class, static fn(Container $container): SearchIndexRebuilder => new SearchIndexRebuilder(
            $container->get(PdoStorage::class),
            $container->get(Indexer::class),
            $container->get('recommendations_cache'),
            ...$container->getByTag(BulkIndexingProviderInterface::class),
        ));

        $container->set(ExtractorInterface::class, static fn(Container $container): CustomExtractor => new CustomExtractor(
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->get(LoggerInterface::class),
        ));

        $container->set('register_search_translator', static function (Container $container) {
            /** @var ExtensibleTranslator $translator */
            $translator = $container->get('translator');
            $translator->attachLoader('register_search', static fn(string $lang): array => require ($dir = __DIR__ . '/resources/lang/') . (file_exists($dir . $lang . '.php') ? $lang : 'English') . '.php');

            return $translator;
        });

        $container->set(SimilarWordsDetector::class, static fn(Container $container): SimilarWordsDetector => new SimilarWordsDetector(
            $container->get(StemmerInterface::class),
        ));
        $container->set(HistoricalTitleSearch::class, static fn(Container $container): HistoricalTitleSearch => new HistoricalTitleSearch(
            $container->get(PdoStorage::class),
            $container->get(HistoricalRussianNormalizer::class),
        ));

        $container->set(SearchPageController::class, static function (Container $container): SearchPageController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new SearchPageController(
                $container->get(Finder::class),
                $container->get(StemmerInterface::class),
                $container->get(HistoricalTitleSearch::class),
                $container->get(ThumbnailGenerator::class),
                $container->get(SimilarWordsDetector::class),
                $container->get(\Register\Content\TagRepository::class),
                $container->get(ArticleProvider::class),
                $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
                $container->get('register_search_translator'),
                $container->get(UrlBuilder::class),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $container->getBoolParameter('debug_view'),
                $provider->getStringProxy('S2_TAGS_URL'),
                $provider->getIntProxy('S2_MAX_ITEMS'),
            );
        });

        $container->set('recommendations_logger', fn(Container $container): \S2\Cms\Logger\Logger => new Logger($container->getStringParameter('log_dir') . 'recommendations.log', 'recommendations', LogLevel::INFO));
        $container->set('recommendations_cache', fn(Container $container): \Symfony\Component\Cache\Adapter\FilesystemAdapter => new FilesystemAdapter('recommendations', 0, $container->getStringParameter('cache_dir')));
        $container->set(RecommendationFinder::class, static fn(Container $container): RecommendationFinder => new RecommendationFinder(
            $container->get(PdoStorage::class),
            $container->get(\PDO::class),
            $container->getStringParameter('db_type'),
            $container->getStringParameter('db_prefix') . 's2_search_idx_',
        ));
        $container->set(LayoutMatcherFactory::class, function (Container $container): LayoutMatcherFactory {
            $provider = $container->get(DynamicConfigProvider::class);
            return new LayoutMatcherFactory(
                $container->get('recommendations_logger'),
                $provider->getIntProxy('S2_SEARCH_RECOMMENDATIONS_LIMIT'),
            );
        });
        $container->set(RecommendationProvider::class, function (Container $container): RecommendationProvider {
            $provider = $container->get(DynamicConfigProvider::class);
            return new RecommendationProvider(
                $container->get(RecommendationFinder::class),
                $container->get(LayoutMatcherFactory::class),
                $container->get('recommendations_cache'),
                $container->get(QueuePublisher::class),
                $provider->getIntProxy('S2_SEARCH_RECOMMENDATIONS_LIMIT'),
            );
        }, [QueueHandlerInterface::class]);
    }

    /** @noinspection HtmlUnknownTarget */
    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(ContentChangedEvent::class, static function (ContentChangedEvent $event) use ($container): void {
            $container->get(QueuePublisher::class)->publish(
                (string)$event->contentId,
                ContentIndexer::QUEUE_CODE,
            );
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_CREATED, static function (TemplateEvent $event) use ($container): void {
            /** @var TranslatorInterface $translator */
            $translator = $container->get('register_search_translator');
            $urlBuilder = $container->get(UrlBuilder::class);
            $quickSearchUrl = $urlBuilder->rawLink(
                '/search',
                $urlBuilder->hasPrefix() ? ['search=1', 'title='] : ['title=']
            );
            $event->htmlTemplate->registerPlaceholder(
                '<!-- s2_search_field -->',
                '<form class="s2_search_form" method="get" action="' . $urlBuilder->link('/search') . '">'
                . ($urlBuilder->hasPrefix() ? '<input type="hidden" name="search" value="1" />' : '')
                . '<input type="text" name="q" id="s2_search_input" data-s2-search-url="'
                . s2_htmlencode($quickSearchUrl) . '" placeholder="' . s2_htmlencode($translator->trans('Search')) . '"/></form>'
            );
        });

        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $event->assetPack->addCss('../../_assets/register/search/search.css', [AssetPack::OPTION_MERGE]);
            $provider = $container->get(DynamicConfigProvider::class);
            if ($provider->getBoolProxy('S2_SEARCH_QUICK')->get()) {
                $event->assetPack
                    ->addJs('../../_assets/register/search/autocomplete.js', [AssetPack::OPTION_MERGE])
                ;
            }
        });

        $eventDispatcher->addListener(ArticleRenderedEvent::class, static function (ArticleRenderedEvent $event) use ($container): void {
            if ($event->template->hasPlaceholder('<!-- s2_recommendations -->')) {
                $recommendationProvider = $container->get(RecommendationProvider::class);
                $requestStack = $container->get(RequestStack::class);
                $request_uri  = $requestStack->getCurrentRequest()?->getPathInfo() ?? '/';
                [$recommendations, $log, $rawRecommendations] = $recommendationProvider->getRecommendations(
                    $request_uri,
                    new ExternalId(SearchDocumentFactory::externalId(ContentId::page($event->articleId))),
                );

                $viewer = $container->get(Viewer::class);

                $event->template->putInPlaceholder('recommendations', $viewer->render('recommendations', [
                    'raw'     => $rawRecommendations,
                    'content' => $recommendations,
                    'log'     => $log,
                ], self::class));
            }
        });

        // Thumbnails in search results page
        $eventDispatcher->addListener(ThumbnailGenerateEvent::class, static function (ThumbnailGenerateEvent $event): void {
            $maxWidth  = $event->maxWidth;
            $maxHeight = $event->maxHeight;
            $src       = $event->src;

            if (str_starts_with($src, CustomExtractor::YOUTUBE_PROTOCOL)) {
                $src = 'https://img.youtube.com/vi/' . substr($src, \strlen(CustomExtractor::YOUTUBE_PROTOCOL)) . '/mqdefault.jpg';

                $sizeArray = ThumbnailGenerator::reduceSize('320', '180', $maxWidth, $maxHeight);

                $event->setResult(\sprintf('<span class="video-thumbnail"><img src="%s" width="%s" height="%s" alt=""></span>', $src, $sizeArray[0], $sizeArray[1]));
            }
        });
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->add('search', new Route('/search', ['_controller' => SearchPageController::class]));

        // Hack for alternative URL schemes
        $routes->add('search2', new Route('/', ['_controller' => SearchPageController::class], condition: "request.query.get('search') !== null"), 2);
    }
}
