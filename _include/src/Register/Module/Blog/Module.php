<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use Psr\Log\LoggerInterface;
use Register\Comment\ContentCommentRenderer;
use Register\Comment\ContentCommentStrategy;
use Register\Content\ContentSourceInterface;
use Register\Content\ContentRepository;
use Register\Content\ContentRenderedEvent;
use Register\Content\ContentViewRepository;
use Register\Content\ContentDeletionGuardInterface;
use Register\Live\LiveUpdateContext;
use Register\Module\Blog\Content\BlogContentSource;
use Register\Module\Blog\Inplace\PostInplaceController;
use Register\Module\Blog\Inplace\PostInplaceControls;
use Register\Module\Blog\Inplace\PostInplaceMediaStorage;
use Register\Module\Blog\Inplace\PostInplaceTokenManager;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Register\Module\Blog\Inplace\PostTagSuggestionsController;
use Register\Core\Admin\Picture\PictureFileNameHelper;
use Register\Core\Admin\Picture\PictureStorageQuota;
use Register\Core\Asset\AssetPack;
use Register\Core\Comment\Antispam\CommentFormTokenManager;
use Register\Core\Comment\Antispam\SpamAssessmentRepository;
use Register\Core\Comment\Antispam\SpamRateLimiter;
use Register\Core\Comment\SpamDecisionProviderInterface;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Controller\CommentController;
use Register\Core\Controller\JsonFeedController;
use Register\Core\Controller\RssController;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerAwareRoutingModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Mail\CommentMailer;
use Register\Core\Model\Article\ArticleRenderedEvent;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\FavoriteArticleProvider;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Model\User\UserProvider;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\TemplateAssetEvent;
use Register\Core\Template\TemplateEvent;
use Register\Core\Template\Viewer;
use Register\Module\Blog\Controller\AllPostsController;
use Register\Module\Blog\Controller\DayPageController;
use Register\Module\Blog\Controller\FavoritePageController;
use Register\Module\Blog\Controller\FlatCommentController;
use Register\Module\Blog\Controller\FlatContentController;
use Register\Module\Blog\Controller\LegacyRssRedirectController;
use Register\Module\Blog\Controller\MainPageController;
use Register\Module\Blog\Controller\MonthPageController;
use Register\Module\Blog\Controller\PostPageController;
use Register\Module\Blog\Controller\RandomPostController;
use Register\Module\Blog\Controller\RankedPostsController;
use Register\Module\Blog\Controller\TagPageController;
use Register\Module\Blog\Controller\TagsPageController;
use Register\Module\Blog\Controller\YearPageController;
use Register\Module\Blog\Model\BlogPlaceholderProvider;
use Register\Module\Blog\Model\ContentRssStrategy;
use Register\Module\Blog\Model\ContentFeedItemProvider;
use Register\Module\Blog\Model\PostProvider;
use Register\Module\Blog\Model\PostFeedRenderer;
use Register\Module\Blog\Model\SiteHeaderRenderer;
use Register\Module\Blog\Model\TagRssStrategy;
use Register\Module\Blog\Service\TagsSearchProvider;
use Register\Module\Search\Event\TagsSearchEvent;
use Register\Module\Search\Service\RecommendationProvider;
use Register\Module\Search\Service\SimilarWordsDetector;
use Register\Url\ContentUrlGenerator;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlAliasController;
use Register\Url\ContentUrlAliasRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Register\Core\Translation\ExtensibleTranslator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class Module implements ContainerModuleInterface, ContainerAwareListenerModuleInterface, ContainerAwareRoutingModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(BlogUrlBuilder::class, static function (Container $container): \Register\Module\Blog\BlogUrlBuilder {
            $provider = $container->get(DynamicConfigProvider::class);
            return new BlogUrlBuilder(
                $container->get(UrlBuilder::class),
                $provider->getStringProxy('REGISTER_TAGS_URL'),
                $provider->getStringProxy('REGISTER_FAVORITE_URL'),
            );
        }, [StatefulServiceInterface::class]);
        $container->set(BlogContentSource::class, static fn(Container $container): BlogContentSource => new BlogContentSource(
            $container->get(DbLayer::class),
            $container->get(ContentUrlGenerator::class),
        ), [ContentSourceInterface::class]);
        $container->set('register_blog_translator', static function (Container $container) {
            /** @var ExtensibleTranslator $translator */
            $translator = $container->get('translator');
            $translator->attachLoader('register_blog', static fn(string $lang): array => require ($dir = __DIR__ . '/resources/lang/') . (file_exists($dir . $lang . '.php') ? $lang : 'English') . '.php');

            return $translator;
        });
        $container->set(CalendarBuilder::class, static function (Container $container): \Register\Module\Blog\CalendarBuilder {
            $provider = $container->get(DynamicConfigProvider::class);
            return new CalendarBuilder(
                $container->get(DbLayer::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ContentUrlGenerator::class),
                $container->get('register_blog_translator'),
                $provider->getIntProxy('REGISTER_START_YEAR'),
            );
        });
        $container->set(BlogPlaceholderProvider::class, static function (Container $container): \Register\Module\Blog\Model\BlogPlaceholderProvider {
            $provider = $container->get(DynamicConfigProvider::class);
            return new BlogPlaceholderProvider(
                $container->get(DbLayer::class),
                $container->get(\Register\Content\TagRepository::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ContentUrlGenerator::class),
                $container->get('register_blog_translator'),
                $container->get(Viewer::class),
                $container->get(RequestStack::class),
                $container->get('config_cache'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getIntProxy('REGISTER_MAX_ITEMS'),
                $container->getStringParameter('url_prefix'),
            );
        });
        $container->set(PostFeedRenderer::class, static function (Container $container): PostFeedRenderer {
            $provider = $container->get(DynamicConfigProvider::class);

            return new PostFeedRenderer(
                $container->get(PostProvider::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(Viewer::class),
                $container->get(PostInplaceControls::class),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $provider->getIntProxy('REGISTER_MAX_ITEMS'),
            );
        });
        $container->set(SiteHeaderRenderer::class, static function (Container $container): SiteHeaderRenderer {
            $provider = $container->get(DynamicConfigProvider::class);

            return new SiteHeaderRenderer(
                $container->get(Viewer::class),
                $container->get(UrlBuilder::class),
                $container->get(PostInplaceControls::class),
                $container->get(PostFeedRenderer::class),
                $provider->getStringProxy('REGISTER_SITE_NAME'),
                $provider->getStringProxy(SiteHeaderRenderer::TAGLINE_CONFIG_KEY),
                $container->get(AuthProvider::class),
                $container->get(\Register\Comment\CommentRepository::class),
                $container->get(LiveUpdateContext::class),
                $container->get(\Register\Auth\PublicAuthRenderer::class),
            );
        });
        $container->set(PostInplaceTokenManager::class, static fn(Container $container): PostInplaceTokenManager => new PostInplaceTokenManager(
            $container->get(\Register\Core\Comment\Antispam\SpamIdentityHasher::class),
        ));
        $container->set(PostInplaceControls::class, static fn(Container $container): PostInplaceControls => new PostInplaceControls(
            $container->get(AuthProvider::class),
            $container->get(PostInplaceTokenManager::class),
            $container->get(UrlBuilder::class),
            $container->get(\Register\Ai\AiSettings::class),
        ));
        $container->set(PostInplaceMediaStorage::class, static fn(Container $container): PostInplaceMediaStorage => new PostInplaceMediaStorage(
            new PictureFileNameHelper(
                $container->get('register_blog_translator'),
                $container->getStringParameter('allowed_extensions'),
            ),
            new PictureStorageQuota(
                $container->get('register_blog_translator'),
                $container->getStringParameter('image_dir'),
                $container->getStringParameter('cache_dir') . 'picture-upload-quota.lock',
                $container->getIntParameter('upload_quota_bytes'),
            ),
            $container->get('register_blog_translator'),
            $container->getStringParameter('image_dir'),
        ));
        $container->set(PostMediaRepository::class, static fn(Container $container): PostMediaRepository => new PostMediaRepository(
            $container->get(DbLayer::class),
            $container->getStringParameter('image_path'),
        ));
        $container->set(PostInplaceController::class, static fn(Container $container): PostInplaceController => new PostInplaceController(
            $container->get(DbLayer::class),
            $container->get(\PDO::class),
            $container->get(PostInplaceControls::class),
            $container->get(PostInplaceTokenManager::class),
            $container->get(\Register\Content\Admin\ContentRevisionService::class),
            $container->get(\Register\Content\TagRepository::class),
            $container->get(\Register\Comment\CommentRepository::class),
            $container->get(\Register\Content\ContentChangeDispatcher::class),
            $container->get(\Register\Live\LiveFragmentRenderer::class),
            $container->get(BlogUrlBuilder::class),
            $container->get(UrlBuilder::class),
            $container->get(PostInplaceMediaStorage::class),
            $container->get(PostMediaRepository::class),
            $container->get(ContentSlugService::class),
            $container->get(ContentUrlGenerator::class),
            $container->get(PostProvider::class),
            $container->get(\Register\Ai\AiClient::class),
            $container->get(\Register\Ai\AiSettings::class),
            $container->get('register_blog_translator'),
            ...$container->getByTag(ContentDeletionGuardInterface::class),
        ));
        $container->set(PostTagSuggestionsController::class, static fn(Container $container): PostTagSuggestionsController => new PostTagSuggestionsController(
            $container->get(AuthProvider::class),
            $container->get(\Register\Content\TagRepository::class),
        ));
        $container->set(MainPageController::class, static function (Container $container): \Register\Module\Blog\Controller\MainPageController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new MainPageController(
                $container->get(DbLayer::class),
                $container->get(CalendarBuilder::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ArticleProvider::class),
                $container->get(PostProvider::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->get('register_blog_translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $container->get(PostFeedRenderer::class),
                $container->get(LiveUpdateContext::class),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
            );
        });
        $container->set(DayPageController::class, static function (Container $container): \Register\Module\Blog\Controller\DayPageController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new DayPageController(
                $container->get(DbLayer::class),
                $container->get(CalendarBuilder::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ArticleProvider::class),
                $container->get(PostProvider::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->get('register_blog_translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
            );
        });
        $container->set(MonthPageController::class, static function (Container $container): \Register\Module\Blog\Controller\MonthPageController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new MonthPageController(
                $container->get(DbLayer::class),
                $container->get(CalendarBuilder::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ArticleProvider::class),
                $container->get(PostProvider::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->get('register_blog_translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $provider->getIntProxy('REGISTER_START_YEAR'),
            );
        });
        $container->set(YearPageController::class, static function (Container $container): \Register\Module\Blog\Controller\YearPageController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new YearPageController(
                $container->get(DbLayer::class),
                $container->get(CalendarBuilder::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ArticleProvider::class),
                $container->get(PostProvider::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->get('register_blog_translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $provider->getIntProxy('REGISTER_START_YEAR'),
            );
        });
        $container->set(PostPageController::class, static function (Container $container): \Register\Module\Blog\Controller\PostPageController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new PostPageController(
                $container->get(DbLayer::class),
                $container->get(CalendarBuilder::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ArticleProvider::class),
                $container->get(PostProvider::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->getIfDefined(RecommendationProvider::class),
                $container->get('register_blog_translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $container->get(ContentCommentRenderer::class),
                $container->get(LiveUpdateContext::class),
                $container->get(PostInplaceControls::class),
                $container->get(\Register\Content\TagRepository::class),
                $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
            );
        });
        $container->set(FlatContentController::class, static fn(Container $container): \Register\Module\Blog\Controller\FlatContentController => new FlatContentController(
            $container->get(ArticleProvider::class),
            $container->get(\Register\Core\Controller\PageCommon::class),
            $container->get(PostPageController::class),
            $container->get(ContentUrlAliasController::class),
            $container->get(PostProvider::class),
            $container->get(UrlBuilder::class),
        ));
        $container->set(ContentUrlAliasController::class, static fn(Container $container): ContentUrlAliasController => new ContentUrlAliasController(
            $container->get(ContentUrlAliasRepository::class),
            $container->get(ContentUrlGenerator::class),
        ));
        $container->set(LegacyRssRedirectController::class, static fn(Container $container): LegacyRssRedirectController => new LegacyRssRedirectController(
            $container->get(UrlBuilder::class),
            $container->getStringParameter('url_prefix'),
        ));
        $container->set(AllPostsController::class, static function (Container $container): \Register\Module\Blog\Controller\AllPostsController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new AllPostsController(
                $container->get(DbLayer::class),
                $container->get(CalendarBuilder::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ArticleProvider::class),
                $container->get(PostProvider::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->get('register_blog_translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
            );
        });
        $container->set(TagsPageController::class, static function (Container $container): \Register\Module\Blog\Controller\TagsPageController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new TagsPageController(
                $container->get(DbLayer::class),
                $container->get(CalendarBuilder::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ArticleProvider::class),
                $container->get(PostProvider::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->get('register_blog_translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $container->get(\Register\Content\TagRepository::class),
            );
        });
        $container->set(TagPageController::class, static function (Container $container): \Register\Module\Blog\Controller\TagPageController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new TagPageController(
                $container->get(DbLayer::class),
                $container->get(CalendarBuilder::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ArticleProvider::class),
                $container->get(PostProvider::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->get('register_blog_translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $container->get(\Register\Content\TagRepository::class),
                $provider->getBoolProxy('REGISTER_USE_HIERARCHY'),
            );
        });
        $container->set(FavoritePageController::class, static function (Container $container): \Register\Module\Blog\Controller\FavoritePageController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new FavoritePageController(
                $container->get(DbLayer::class),
                $container->get(CalendarBuilder::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ArticleProvider::class),
                $container->get(PostProvider::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->get('register_blog_translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $container->get(FavoriteArticleProvider::class),
            );
        });
        $container->set(RankedPostsController::class, static function (Container $container): RankedPostsController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new RankedPostsController(
                $container->get(DbLayer::class),
                $container->get(CalendarBuilder::class),
                $container->get(BlogUrlBuilder::class),
                $container->get(ArticleProvider::class),
                $container->get(PostProvider::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->get('register_blog_translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $container->get(ContentViewRepository::class),
                $provider->getIntProxy('REGISTER_MAX_ITEMS'),
            );
        });
        $container->set(RandomPostController::class, static fn(Container $container): RandomPostController => new RandomPostController(
            $container->get(PostProvider::class),
            $container->get(BlogUrlBuilder::class),
        ));
        $container->set(ContentRssStrategy::class, static function (Container $container): ContentRssStrategy {
            $provider = $container->get(DynamicConfigProvider::class);
            return new ContentRssStrategy(
                $container->get(ContentRepository::class),
                $container->get(ContentFeedItemProvider::class),
                $container->get(BlogUrlBuilder::class),
                $container->get('register_blog_translator'),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
            );
        });
        $container->set(ContentFeedItemProvider::class, static fn(Container $container): ContentFeedItemProvider => new ContentFeedItemProvider(
            $container->get(PostProvider::class),
            $container->get(\Register\Content\TagRepository::class),
            $container->get(BlogUrlBuilder::class),
            $container->get(ContentUrlGenerator::class),
            $container->get('register_blog_translator'),
            $container->get('strict_viewer'),
        ));
        $container->set(TagRssStrategy::class, static function (Container $container): TagRssStrategy {
            $provider = $container->get(DynamicConfigProvider::class);
            return new TagRssStrategy(
                $container->get(RequestStack::class),
                $container->get(\Register\Content\TagRepository::class),
                $container->get(ContentRepository::class),
                $container->get(ContentFeedItemProvider::class),
                $container->get(BlogUrlBuilder::class),
                $container->get('register_blog_translator'),
                $provider->getStringProxy('REGISTER_BLOG_TITLE'),
            );
        });
        $container->set(RssController::class, static function (Container $container): RssController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new RssController(
                $container->get(ContentRssStrategy::class),
                $container->get(UrlBuilder::class),
                $container->get('strict_viewer'),
                $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
                $container->getStringParameter('base_url'),
                $container->getStringParameter('version'),
                $provider->getStringProxy('REGISTER_WEBMASTER'),
            );
        });
        $jsonFeedController = static fn(Container $container, string $strategy): JsonFeedController => new JsonFeedController(
            $container->get($strategy),
            $container->get(UrlBuilder::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->getStringParameter('base_url'),
            $container->get(DynamicConfigProvider::class)->getStringProxy('REGISTER_WEBMASTER'),
        );
        $container->set('register_blog.json_feed_controller', static fn(Container $container): JsonFeedController => $jsonFeedController($container, ContentRssStrategy::class));
        $container->set('register_blog.tag_json_feed_controller', static fn(Container $container): JsonFeedController => $jsonFeedController($container, TagRssStrategy::class));
        $container->set('register_blog.tag_rss_controller', static function (Container $container): RssController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new RssController(
                $container->get(TagRssStrategy::class),
                $container->get(UrlBuilder::class),
                $container->get('strict_viewer'),
                $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
                $container->getStringParameter('base_url'),
                $container->getStringParameter('version'),
                $provider->getStringProxy('REGISTER_WEBMASTER'),
            );
        });
        $container->set('register_blog.comment_controller', static function (Container $container): \Register\Core\Controller\CommentController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new CommentController(
                $container->get(AuthProvider::class),
                $container->get(UserProvider::class),
                $container->get(ContentCommentStrategy::POST_SERVICE_ID),
                $container->get('comments_translator'),
                $container->get(UrlBuilder::class),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $container->get(LoggerInterface::class),
                $container->get(CommentMailer::class),
                $container->get(SpamDecisionProviderInterface::class),
                $container->get(CommentFormTokenManager::class),
                $container->get(SpamRateLimiter::class),
                $container->get(SpamAssessmentRepository::class),
                $container->get(\Register\Module\VisitorIdentity\VisitorIdentityManager::class),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $provider->getBoolProxy('REGISTER_PREMODERATION'),
                $container->get(\Register\Core\Controller\Comment\PendingEmailCommentServiceInterface::class),
            );
        }, ['dynamic_config_dependent']);
        $container->set(FlatCommentController::class, static fn(Container $container): \Register\Module\Blog\Controller\FlatCommentController => new FlatCommentController(
            $container->get(ArticleProvider::class),
            $container->get(CommentController::class),
            $container->get('register_blog.comment_controller'),
        ));

        $container->set(PostProvider::class, static fn(Container $container): \Register\Module\Blog\Model\PostProvider => new PostProvider(
            $container->get(DbLayer::class),
            $container->get(\Register\Comment\CommentRepository::class),
            $container->get(\Register\Content\TagRepository::class),
            $container->get(BlogUrlBuilder::class),
            $container->get(ContentUrlGenerator::class),
            $container->get(Viewer::class),
            $container->get(ContentViewRepository::class),
        ));

        $container->set(TagsSearchProvider::class, static fn(Container $container): \Register\Module\Blog\Service\TagsSearchProvider => new TagsSearchProvider(
            $container->get(\Register\Content\TagRepository::class),
            $container->get(SimilarWordsDetector::class),
            $container->get(BlogUrlBuilder::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(ContentRenderedEvent::class, static function (ContentRenderedEvent $event) use ($container): void {
            $request = $container->get(RequestStack::class)->getCurrentRequest();
            $purpose = strtolower(trim(implode(' ', [
                $request?->headers->get('Purpose', '') ?? '',
                $request?->headers->get('Sec-Purpose', '') ?? '',
            ])));
            if (!$request instanceof \Symfony\Component\HttpFoundation\Request
                || !$request->isMethod('GET')
                || str_contains($purpose, 'prefetch')
                || $container->get(\Register\Module\Analytics\BotDetector::class)->isBot($request->headers->get('User-Agent', '') ?? '')
            ) {
                return;
            }

            $container->get(ContentViewRepository::class)->record($event->contentId);
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_PRE_REPLACE, static function (TemplateEvent $event) use ($container): void {
            if (!$event->htmlTemplate->hasPlaceholder('<!-- register_site_header -->')) {
                return;
            }

            $request = $container->get(RequestStack::class)->getCurrentRequest();
            if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
                return;
            }

            $event->htmlTemplate->registerPlaceholder(
                '<!-- register_site_header -->',
                $container->get(SiteHeaderRenderer::class)->render($request),
            );
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_CREATED, function (TemplateEvent $event) use ($container): void {
            $blogPlaceholders = [];
            $template         = $event->htmlTemplate;

            foreach (['register_blog_last_comments', 'register_blog_last_discussions', 'register_blog_last_post', 'register_blog_navigation'] as $blogPlaceholder) {
                if ($template->hasPlaceholder('<!-- ' . $blogPlaceholder . ' -->')) {
                    $blogPlaceholders[$blogPlaceholder] = 1;
                }
            }

            if (\count($blogPlaceholders) === 0) {
                return;
            }

            /** @var TranslatorInterface $translator */
            $translator = $container->get('register_blog_translator');

            $viewer = $container->get(Viewer::class);

            if (isset($blogPlaceholders['register_blog_last_comments'])) {
                $placeholderProvider = $container->get(BlogPlaceholderProvider::class);
                $recentComments      = $placeholderProvider->getRecentComments();

                $template->registerPlaceholder('<!-- register_blog_last_comments -->', $recentComments === [] ? '' : $viewer->render('menu_comments', [
                    'title' => $translator->trans('Last blog comments'),
                    'menu'  => $recentComments,
                ]));
            }

            if (isset($blogPlaceholders['register_blog_last_discussions'])) {
                $placeholderProvider = $container->get(BlogPlaceholderProvider::class);
                $lastDiscussions     = $placeholderProvider->getRecentDiscussions();

                $template->registerPlaceholder('<!-- register_blog_last_discussions -->', $lastDiscussions === [] ? '' : $viewer->render('menu_block', [
                    'title' => $translator->trans('Last blog discussions'),
                    'menu'  => $lastDiscussions,
                    'class' => 'register_blog_last_discussions',
                ]));
            }

            if (isset($blogPlaceholders['register_blog_last_post'])) {
                $postProvider = $container->get(PostProvider::class);
                $lastPosts    = $postProvider->lastPostsArray(1);

                foreach ($lastPosts as &$register_blog_post) {
                    $register_blog_post = $viewer->render('post_short', $register_blog_post, self::class);
                }

                unset($register_blog_post);
                $template->registerPlaceholder('<!-- register_blog_last_post -->', implode('', $lastPosts));
            }

            if (isset($blogPlaceholders['register_blog_navigation'])) {
                $placeholderProvider = $container->get(BlogPlaceholderProvider::class);
                $template->registerPlaceholder('<!-- register_blog_navigation -->', $viewer->render(
                    'navigation',
                    $placeholderProvider->getBlogNavigationData(),
                    self::class
                ));
            }
        });

        $eventDispatcher->addListener(ArticleRenderedEvent::class, static function (ArticleRenderedEvent $event) use ($container): void {
            if ($event->template->hasPlaceholder('<!-- register_blog_tags -->')) {
                $viewer = $container->get(Viewer::class);
                /** @var TranslatorInterface $translator */
                $translator = $container->get('register_blog_translator');
                $placeholderProvider = $container->get(BlogPlaceholderProvider::class);

                $register_blog_tags = $placeholderProvider->getBlogTagsForArticle($event->articleId);
                $event->template->registerPlaceholder('<!-- register_blog_tags -->', $register_blog_tags === [] ? '' : $viewer->render('menu_block', [
                    'title' => $translator->trans('See in blog'),
                    'menu'  => $register_blog_tags,
                    'class' => 'register_blog_tags',
                ]));
            }
        });

        $eventDispatcher->addListener(TagsSearchEvent::class, static function (TagsSearchEvent $event) use ($container): void {
            $tagsSearchProvider = $container->get(TagsSearchProvider::class);
            $blogTagLinks       = $tagsSearchProvider->findBlogTags($event->words);

            if (\count($blogTagLinks) > 0) {
                /** @var TranslatorInterface $translator */
                $translator = $container->get('register_blog_translator');
                if ($event->getLine() !== null) {
                    $event->addShortLine(\sprintf($translator->trans('Found blog tags short'), implode(', ', $blogTagLinks)));
                } else {
                    $event->addLine(\sprintf($translator->trans('Found blog tags'), implode(', ', $blogTagLinks)));
                }
            }
        });

        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $basePath = rtrim($container->getStringParameter('base_path'), '/');
            $editorFilename = $container->getStringParameter('public_root_dir') . '_assets/register/post-inplace.js';
            $editorModifiedAt = \filemtime($editorFilename);
            if ($editorModifiedAt === false) {
                throw new \LogicException(\sprintf('Unable to read the modification time of "%s".', $editorFilename));
            }

            $event->assetPack
                ->addCss('../../_assets/register/blog/site.css', [AssetPack::OPTION_MERGE])
                ->addJs($basePath . '/_assets/register/post-inplace.js?v=' . $editorModifiedAt, [AssetPack::OPTION_DEFER])
            ;
        });
    }

    /**
     * {@inheritdoc}
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
        $configProvider = $container->get(DynamicConfigProvider::class);
        $favoriteUrl    = $configProvider->getStringProxy('REGISTER_FAVORITE_URL')->get();
        $tagsUrl        = $configProvider->getStringProxy('REGISTER_TAGS_URL')->get();
        $priority       = 1;
        $flatPriority   = -1;

        $routes->add('blog_post_inplace', new Route(
            '/_inplace/post/{id<[1-9][0-9]*>}',
            ['_controller' => PostInplaceController::class],
            methods: ['POST'],
        ), $priority + 1);

        $routes->add('blog_post_inplace_create', new Route(
            '/_inplace/post/new',
            ['_controller' => PostInplaceController::class, 'create' => true],
            methods: ['POST'],
        ), $priority + 1);

        $routes->add('blog_post_tag_suggestions', new Route(
            '/_inplace/tags',
            ['_controller' => PostTagSuggestionsController::class],
            methods: ['GET'],
        ), $priority + 1);

        $routes->add('blog_main', new Route(
            '/',
            ['_controller' => MainPageController::class, 'page' => 0, 'slash' => '/'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);

        $routes->add('blog_main_pages', new Route(
            '/skip/{page<\d+>}',
            ['_controller' => MainPageController::class, 'slash' => '/'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);

        $routes->add('blog_rss', new Route(
            '/rss',
            ['_controller' => RssController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_json_feed', new Route(
            '/feed.json',
            ['_controller' => 'register_blog.json_feed_controller'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_rss_legacy', new Route(
            '/rss.xml',
            ['_controller' => LegacyRssRedirectController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_all', new Route(
            '/all{slash</?>}',
            ['_controller' => AllPostsController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);

        $routes->add('blog_favorite', new Route(
            '/' . $favoriteUrl . '{slash</?>}',
            ['_controller' => FavoritePageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_popular', new Route(
            '/popular{slash</?>}',
            ['_controller' => RankedPostsController::class, 'ranking' => 'popular'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_hot', new Route(
            '/hot{slash</?>}',
            ['_controller' => RankedPostsController::class, 'ranking' => 'hot'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_random', new Route(
            '/random{slash</?>}',
            ['_controller' => RandomPostController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);

        $routes->add('blog_tags', new Route(
            '/' . $tagsUrl . '{slash</?>}',
            ['_controller' => TagsPageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_tag_rss', new Route(
            '/' . $tagsUrl . '/{tag}/rss',
            ['_controller' => 'register_blog.tag_rss_controller'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority + 1);
        $routes->add('blog_tag_json_feed', new Route(
            '/' . $tagsUrl . '/{tag}/feed.json',
            ['_controller' => 'register_blog.tag_json_feed_controller'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority + 1);
        $routes->add('blog_tag', new Route(
            '/' . $tagsUrl . '/{tag}{slash</?>}',
            ['_controller' => TagPageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);

        $routes->add('blog_year', new Route(
            '/archive/{year<\d{4}>}/',
            ['_controller' => YearPageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_month', new Route(
            '/archive/{year<\d{4}>}/{month<\d{2}>}/',
            ['_controller' => MonthPageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_day', new Route(
            '/archive/{year<\d{4}>}/{month<\d{2}>}/{day<\d{2}>}/',
            ['_controller' => DayPageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_post', new Route(
            '/{url}',
            ['_controller' => FlatContentController::class],
            requirements: ['url' => '.+'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $flatPriority);
        $routes->add('blog_comment', new Route(
            '/{url}',
            ['_controller' => FlatCommentController::class],
            requirements: ['url' => '.+'],
            options: ['utf8' => true],
            methods: ['POST'],
        ), $flatPriority);
        $routes->add('blog_url_alias', new Route(
            '/{alias}',
            ['_controller' => ContentUrlAliasController::class],
            requirements: ['alias' => '.+'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $flatPriority - 1);
    }
}
