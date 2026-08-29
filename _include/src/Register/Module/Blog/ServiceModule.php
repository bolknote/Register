<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use Psr\Log\LoggerInterface;
use Register\Comment\CommentMailPublisher;
use Register\Comment\ContentCommentRenderer;
use Register\Comment\ContentCommentStrategy;
use Register\Content\ContentSourceInterface;
use Register\Content\ContentRepository;
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
use Register\Admin\Picture\PictureFileNameHelper;
use Register\Admin\Picture\PictureStorageQuota;
use Register\Core\Comment\Antispam\CommentFormTokenManager;
use Register\Comment\Antispam\SpamAssessmentRepository;
use Register\Core\Comment\Antispam\SpamRateLimiter;
use Register\Core\Comment\SpamDecisionProviderInterface;
use Register\Core\Config\DynamicConfigProvider;
use Register\Controller\CommentController;
use Register\Core\Controller\JsonFeedController;
use Register\Core\Controller\RssController;
use Register\Core\Controller\Rss\FeedSettings;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Framework\ResponseProcessorInterface;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Http\Cache\PageCachePools;
use Register\Model\ArticleProvider;
use Register\Model\FavoriteArticleProvider;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Model\User\UserProvider;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\PDO as TrackedPDO;
use Register\Core\Template\HtmlTemplateProvider;
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
use Register\Module\Blog\Model\BlogPageCache;
use Register\Module\Blog\Model\BlogResponseCachePolicy;
use Register\Module\Blog\Model\BlogSidebarResponseProcessor;
use Register\Module\Blog\Model\ContentRssStrategy;
use Register\Module\Blog\Model\ContentViewResponseProcessor;
use Register\Module\Blog\Model\ContentFeedItemProvider;
use Register\Module\Blog\Model\PostProvider;
use Register\Module\Blog\Model\PostFeedRenderer;
use Register\Module\Blog\Model\SiteHeaderRenderer;
use Register\Module\Blog\Model\TagRssStrategy;
use Register\Module\Blog\Service\TagsSearchProvider;
use Register\Module\Analytics\BotDetector;
use Register\Module\Search\Service\RecommendationProvider;
use Register\Module\Search\Service\SimilarWordsDetector;
use Register\Url\ContentUrlGenerator;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlAliasController;
use Register\Url\ContentUrlAliasRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Register\Core\Translation\ExtensibleTranslator;

final class ServiceModule implements ContainerModuleInterface
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
                $container->get(BlogPageCache::class),
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
                $container->get(BlogPageCache::class),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getIntProxy('REGISTER_MAX_ITEMS'),
                $container->getStringParameter('url_prefix'),
            );
        });
        $container->set(BlogPageCache::class, static function (Container $container): BlogPageCache {
            $pageCachePools = $container->get(PageCachePools::class);
            $pdo = $container->get(\PDO::class);
            if (!$pdo instanceof TrackedPDO) {
                throw new \LogicException("The page cache requires Register's transaction-aware PDO service.");
            }

            return new BlogPageCache(
                $pageCachePools->persistent,
                $container->getBoolParameter('disable_cache'),
                $pageCachePools->hot,
                $pdo,
            );
        }, [StatefulServiceInterface::class]);
        $container->set(BlogResponseCachePolicy::class, static fn(Container $container): BlogResponseCachePolicy => new BlogResponseCachePolicy(
            $container->get(AuthProvider::class),
            $container->get(\Register\Module\VisitorIdentity\VisitorIdentityManager::class),
            $container->get(BotDetector::class),
        ));
        $container->set(ContentViewResponseProcessor::class, static fn(Container $container): ContentViewResponseProcessor => new ContentViewResponseProcessor(
            $container->get(ContentViewRepository::class),
            $container->get(BotDetector::class),
            $container->get('register_blog_translator'),
        ), [ResponseProcessorInterface::class]);
        $container->set(BlogSidebarResponseProcessor::class, static fn(Container $container): BlogSidebarResponseProcessor => new BlogSidebarResponseProcessor(
            $container->get(BlogPlaceholderProvider::class),
            $container->get(Viewer::class),
            $container->get('register_blog_translator'),
        ), [ResponseProcessorInterface::class]);
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
                $container->get(BlogPageCache::class),
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
            $container->getStringParameter('content_image_directory'),
            $container->getStringParameter('cache_dir'),
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
            $container->get(\Register\Content\PublicationMetadataGenerator::class),
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
                $container->get(BlogPageCache::class),
                $container->get(BlogResponseCachePolicy::class),
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
                $container->get(\Register\Module\VisitorIdentity\VisitorIdentityManager::class),
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
            $container->get(\Register\Controller\PageCommon::class),
            $container->get(PostPageController::class),
            $container->get(ContentUrlAliasController::class),
            $container->get(PostProvider::class),
            $container->get(UrlBuilder::class),
            $container->get(BlogPageCache::class),
            $container->get(BlogResponseCachePolicy::class),
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
                $container->get(BlogPageCache::class),
                $container->get(BlogResponseCachePolicy::class),
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
                $provider->getIntProxy('REGISTER_MAX_ITEMS'),
                $container->get(PostInplaceControls::class),
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
                $container->get(FeedSettings::class),
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
                $container->get(FeedSettings::class),
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
        $container->set('register_blog.comment_controller', static function (Container $container): \Register\Controller\CommentController {
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
                $container->get(CommentMailPublisher::class),
                $container->get(SpamDecisionProviderInterface::class),
                $container->get(CommentFormTokenManager::class),
                $container->get(SpamRateLimiter::class),
                $container->get(SpamAssessmentRepository::class),
                $container->get(\Register\Module\VisitorIdentity\VisitorIdentityManager::class),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $provider->getBoolProxy('REGISTER_PREMODERATION'),
                $container->get(\Register\Controller\Comment\PendingEmailCommentServiceInterface::class),
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
            $container->get(BlogPageCache::class),
        ));

        $container->set(TagsSearchProvider::class, static fn(Container $container): \Register\Module\Blog\Service\TagsSearchProvider => new TagsSearchProvider(
            $container->get(\Register\Content\TagRepository::class),
            $container->get(SimilarWordsDetector::class),
            $container->get(BlogUrlBuilder::class),
        ));
    }

}
