<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use S2\Cms\Asset\AssetMergeFactory;
use S2\Cms\Comment\AkismetProxy;
use S2\Cms\Comment\SpamDetectorInterface;
use S2\Cms\Comment\SpamDecisionProvider;
use S2\Cms\Comment\SpamDecisionProviderInterface;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Controller\Comment\CommentStrategyInterface;
use S2\Cms\Controller\CommentController;
use S2\Cms\Controller\CommentSentController;
use S2\Cms\Controller\CommentUnsubscribeController;
use S2\Cms\Controller\NotFoundController;
use S2\Cms\Controller\PageCommon;
use S2\Cms\Controller\PageFavorite;
use S2\Cms\Controller\PageTag;
use S2\Cms\Controller\PageTags;
use S2\Cms\Controller\RssController;
use S2\Cms\Controller\Sitemap;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\Event\NotFoundEvent;
use S2\Cms\Framework\Exception\ConfigurationException;
use S2\Cms\Framework\ExtensionInterface;
use S2\Cms\Framework\StatefulServiceInterface;
use S2\Cms\Http\RedirectDetector;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\Image\ThumbnailGenerator;
use S2\Cms\Logger\Logger;
use S2\Cms\Mail\CommentMailer;
use S2\Cms\Model\Article\ArticleRssStrategy;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\AuthProvider;
use S2\Cms\Model\Comment\ArticleCommentStrategy;
use S2\Cms\Model\CommentNotifier;
use S2\Cms\Model\CommentProvider;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Model\FavoriteArticleProvider;
use S2\Cms\Model\MigrationManager;
use S2\Cms\Model\TagsProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Model\User\UserProvider;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerPostgres;
use S2\Cms\Pdo\DbLayerSqlite;
use S2\Cms\Pdo\PDO;
use S2\Cms\Pdo\PdoSqliteFactory;
use S2\Cms\Queue\QueueConsumer;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Template\HtmlTemplateProvider;
use S2\Cms\Template\TemplateEvent;
use S2\Cms\Template\TemplateFinalReplaceEvent;
use S2\Cms\Template\Viewer;
use S2\Cms\Translation\ExtensibleTranslator;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use S2\Cms\Framework\Exception\ServiceAlreadyDefinedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class CmsExtension implements ExtensionInterface
{
    /**
     * @throws ServiceAlreadyDefinedException
     */
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(DbLayer::class, function (Container $container): \S2\Cms\Pdo\DbLayer|\S2\Cms\Pdo\DbLayerSqlite|\S2\Cms\Pdo\DbLayerPostgres {
            $db_prefix = $container->getStringParameter('db_prefix');
            $db_type   = $container->getStringParameter('db_type');

            return match ($db_type) {
                'mysql' => new DbLayer($container->get(\PDO::class), $db_prefix),
                'sqlite' => new DbLayerSqlite($container->get(\PDO::class), $db_prefix),
                'pgsql' => new DbLayerPostgres($container->get(\PDO::class), $db_prefix),
                default => throw new \RuntimeException(\sprintf('Unsupported db_type="%s"', $db_type)),
            };
        });
        $container->set(\PDO::class, function (Container $container): \S2\Cms\Pdo\PDO {
            $container->getStringParameter('db_prefix');
            $db_type     = $container->getStringParameter('db_type');
            $db_host     = $container->getStringParameter('db_host');
            $db_name     = $container->getStringParameter('db_name');
            $db_username = $container->getStringParameter('db_username');
            $db_password = $container->getStringParameter('db_password');
            $p_connect   = $container->getBoolParameter('p_connect');

            if (!class_exists(\PDO::class)) {
                throw new \RuntimeException('This PHP environment does not have PDO support built in. PDO support is required. Consult the PHP documentation for further assistance.');
            }

            if (!\in_array($db_type, \PDO::getAvailableDrivers(), true)) {
                throw new \RuntimeException(\sprintf('This PHP environment does not have PDO "%s" support built in. It is required if you want to use this type of database. Consult the PHP documentation for further assistance.', $db_type));
            }

            return match ($db_type) {
                'mysql' => new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_username, $db_password),
                'sqlite' => PdoSqliteFactory::create($container->getStringParameter('root_dir') . $db_name, $p_connect),
                'pgsql' => new PDO("pgsql:host=$db_host;dbname=$db_name", $db_username, $db_password),
                default => throw new \RuntimeException(\sprintf('Unsupported db_type="%s"', $db_type)),
            };
        }, [StatefulServiceInterface::class]);

        $container->set(MigrationManager::class, fn(Container $container): \S2\Cms\Model\MigrationManager => new MigrationManager(
            $container->get(DbLayer::class),
            $container->getStringParameter('db_type'),
        ));

        $container->set(ExtensionCache::class, fn(Container $container): \S2\Cms\Model\ExtensionCache => new ExtensionCache(
            $container->get(DbLayer::class),
            $container->getBoolParameter('disable_cache'),
            $container->getStringParameter('cache_dir'),
        ));

        $container->set(ThumbnailGenerator::class, fn(Container $container): \S2\Cms\Image\ThumbnailGenerator => new ThumbnailGenerator(
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->get(QueuePublisher::class),
            $container->getStringParameter('image_path'),
            $container->getStringParameter('image_dir'),
        ), [QueueHandlerInterface::class]);
        $container->set(LoggerInterface::class, fn(Container $container): \S2\Cms\Logger\Logger => new Logger($container->getStringParameter('log_dir') . 'app.log', 'app', LogLevel::INFO));
        $container->set('config_cache', fn(Container $container): \Symfony\Component\Cache\Adapter\FilesystemAdapter => new FilesystemAdapter('config', 0, $container->getStringParameter('cache_dir')));

        $container->set(DynamicConfigProvider::class, fn(Container $container): \S2\Cms\Config\DynamicConfigProvider => new DynamicConfigProvider(
            $container->get(DbLayer::class),
            $container->getStringParameter('cache_dir') . 'cache_config.php',
            $container->getBoolParameter('disable_cache'),
        ), [StatefulServiceInterface::class]); // TODO not enough, parameters are set into many other services

        $container->set('translator', function (Container $container): \S2\Cms\Translation\ExtensibleTranslator {
            $provider = $container->get(DynamicConfigProvider::class);
            $language = $provider->getStringProxy('S2_LANGUAGE');

            $translator = new ExtensibleTranslator($language);

            $translator->attachLoader('common', function (string $language, ExtensibleTranslator $translator): array {
                $fileName = __DIR__ . '/../../_lang/' . $language . '/common.php';
                if (!\file_exists($fileName)) {
                    throw new ConfigurationException(\sprintf('The language pack "%s" you have chosen seems to be corrupt. Please check that file "common.php" exists.', $language));
                }

                $translations = require $fileName;
                if (!\is_array($translations)) {
                    throw new ConfigurationException(\sprintf('The language pack "%s" you have chosen seems to be corrupt. Please check that file "common.php" has the correct format.', $language));
                }

                $locale = $translations['locale'] ?? 'en';

                $translator->setLocale($locale);

                return $translations;
            });

            return $translator;
        }, [StatefulServiceInterface::class]);

        $container->set(QueuePublisher::class, fn(Container $container): \S2\Cms\Queue\QueuePublisher => new QueuePublisher(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
        ));
        $container->set(QueueConsumer::class, fn(Container $container): \S2\Cms\Queue\QueueConsumer => new QueueConsumer(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
            $container->get(LoggerInterface::class),
            ...$container->getByTag(QueueHandlerInterface::class)
        ));

        $container->set(UrlBuilder::class, fn(Container $container): \S2\Cms\Model\UrlBuilder => new UrlBuilder(
            $container->getStringParameter('base_path'),
            $container->getStringParameter('base_url'),
            $container->getStringParameter('url_prefix'),
        ));

        $container->set(RequestStack::class, fn(Container $_container): \Symfony\Component\HttpFoundation\RequestStack => new RequestStack());

        $container->set(HttpClient::class, fn(Container $_container): \S2\Cms\HttpClient\HttpClient => new HttpClient());
        $container->set('asset_http_client', fn(Container $_container): \S2\Cms\HttpClient\HttpClient => new HttpClient(verifySsl: true));

        $container->set(AssetMergeFactory::class, fn(Container $container): \S2\Cms\Asset\AssetMergeFactory => new AssetMergeFactory(
            $container->get('asset_http_client'),
            $container->get(LoggerInterface::class),
            $container->getBoolParameter('debug'),
            // Not a cache_dir since it can be overridden via the config.php, but we need a public available path
            $container->getStringParameter('root_dir') . '_cache/',
            $container->getStringParameter('base_path') . '/_cache/',
            $container->getBoolParameter('disable_cache'),
        ));

        $container->set(HtmlTemplateProvider::class, function (Container $container): \S2\Cms\Template\HtmlTemplateProvider {
            $provider = $container->get(DynamicConfigProvider::class);
            return new HtmlTemplateProvider(
                $container->get(RequestStack::class),
                $container->get(UrlBuilder::class),
                $container->get('translator'),
                $container->get(Viewer::class),
                $container->get(AssetMergeFactory::class),
                $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
                $provider->getStringProxy('S2_STYLE'),
                $provider->getStringProxy('S2_SITE_NAME'),
                $provider->getBoolProxy('S2_ENABLED_COMMENTS'),
                $provider->getStringProxy('S2_WEBMASTER'),
                $provider->getStringProxy('S2_WEBMASTER_EMAIL'),
                $provider->getIntProxy('S2_START_YEAR'),
                $container->getBoolParameter('debug_view'),
                $container->getStringParameter('root_dir'),
                $container->getStringParameter('base_path'),
                $container->getNullableStringParameter('canonical_url'),
            );
        });

        $container->set(Viewer::class, function (Container $container): \S2\Cms\Template\Viewer {
            $provider = $container->get(DynamicConfigProvider::class);
            return new Viewer(
                $container->get('translator'),
                $container->get(UrlBuilder::class),
                $container->getStringParameter('root_dir'),
                $provider->getStringProxy('S2_STYLE'),
                $container->getBoolParameter('debug_view')
            );
        });

        $container->set('strict_viewer', function (Container $container): \S2\Cms\Template\Viewer {
            $provider = $container->get(DynamicConfigProvider::class);
            return new Viewer(
                $container->get('translator'),
                $container->get(UrlBuilder::class),
                $container->getStringParameter('root_dir'),
                $provider->getStringProxy('S2_STYLE'),
                false // no HTML debug info for XML and other non-HTML content
            );
        });

        $container->set(ArticleProvider::class, function (Container $container): \S2\Cms\Model\ArticleProvider {
            $provider = $container->get(DynamicConfigProvider::class);
            return new ArticleProvider(
                $container->get(DbLayer::class),
                $container->get(UrlBuilder::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('S2_FAVORITE_URL'),
                $provider->getBoolProxy('S2_USE_HIERARCHY'),
            );
        });

        $container->set(TagsProvider::class, function (Container $container): \S2\Cms\Model\TagsProvider {
            $provider = $container->get(DynamicConfigProvider::class);
            return new TagsProvider(
                $container->get(DbLayer::class),
                $container->get(UrlBuilder::class),
                $provider->getStringProxy('S2_TAGS_URL'),
            );
        }, [StatefulServiceInterface::class]);

        $container->set(CommentProvider::class, function (Container $container): \S2\Cms\Model\CommentProvider {
            $provider = $container->get(DynamicConfigProvider::class);
            return new CommentProvider(
                $container->get(DbLayer::class),
                $container->get(ArticleProvider::class),
                $container->get(UrlBuilder::class),
                $container->get(Viewer::class),
                $provider->getBoolProxy('S2_SHOW_COMMENTS'),
            );
        });

        $container->set(RedirectDetector::class, fn(Container $container): \S2\Cms\Http\RedirectDetector => new RedirectDetector(
            $container->get(UrlBuilder::class),
            $container->getArrayParameter('redirect_map'),
        ));

        $container->set(NotFoundController::class, fn(Container $container): \S2\Cms\Controller\NotFoundController => new NotFoundController(
            $container->get(ArticleProvider::class),
            $container->get(UrlBuilder::class),
            $container->get('translator'),
            $container->get(HtmlTemplateProvider::class),
        ));

        $container->set(PageFavorite::class, fn(Container $container): \S2\Cms\Controller\PageFavorite => new PageFavorite(
            $container->get(FavoriteArticleProvider::class),
            $container->get(ArticleProvider::class),
            $container->get(UrlBuilder::class),
            $container->get('translator'),
            $container->get(HtmlTemplateProvider::class),
        ));

        $container->set(FavoriteArticleProvider::class, function (Container $container): \S2\Cms\Model\FavoriteArticleProvider {
            $provider = $container->get(DynamicConfigProvider::class);
            return new FavoriteArticleProvider(
                $container->get(DbLayer::class),
                $container->get(ArticleProvider::class),
                $container->get(UrlBuilder::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('S2_FAVORITE_URL'),
                $provider->getBoolProxy('S2_USE_HIERARCHY'),
            );
        });

        $container->set(PageTags::class, fn(Container $container): \S2\Cms\Controller\PageTags => new PageTags(
            $container->get(TagsProvider::class),
            $container->get(ArticleProvider::class),
            $container->get(UrlBuilder::class),
            $container->get('translator'),
            $container->get(HtmlTemplateProvider::class),
            $container->get(Viewer::class),
        ));

        $container->set(PageTag::class, function (Container $container): \S2\Cms\Controller\PageTag {
            $provider = $container->get(DynamicConfigProvider::class);
            return new PageTag(
                $container->get(DbLayer::class),
                $container->get(ArticleProvider::class),
                $container->get(UrlBuilder::class),
                $container->get('translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('S2_TAGS_URL'),
                $provider->getStringProxy('S2_FAVORITE_URL'),
                $provider->getBoolProxy('S2_USE_HIERARCHY'),
            );
        });

        $container->set(PageCommon::class, function (Container $container): \S2\Cms\Controller\PageCommon {
            $provider = $container->get(DynamicConfigProvider::class);
            return new PageCommon(
                $container->get(DbLayer::class),
                $container->get(ArticleProvider::class),
                $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
                $container->get(UrlBuilder::class),
                $container->get('translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getBoolProxy('S2_USE_HIERARCHY'),
                $provider->getBoolProxy('S2_SHOW_COMMENTS'),
                $provider->getStringProxy('S2_TAGS_URL'),
                $provider->getStringProxy('S2_FAVORITE_URL'),
                $provider->getIntProxy('S2_MAX_ITEMS'),
                $container->getBoolParameter('debug'),
            );
        });

        $container->set(CommentMailer::class, function (Container $container): \S2\Cms\Mail\CommentMailer {
            $provider = $container->get(DynamicConfigProvider::class);
            return new CommentMailer(
                $container->get('comments_translator'),
                $provider->getStringProxy('S2_WEBMASTER'),
                $provider->getStringProxy('S2_WEBMASTER_EMAIL'),
            );
        });

        $container->set(CommentNotifier::class, fn(Container $container): \S2\Cms\Model\CommentNotifier => new CommentNotifier(
            $container->get(DbLayer::class),
            $container->get(ArticleProvider::class),
            $container->get(UrlBuilder::class),
            $container->get(CommentMailer::class),
        ));

        $container->set('comments_translator', function (Container $container) {
            /** @var ExtensibleTranslator $translator */
            $translator = $container->get('translator');
            $translator->attachLoader('comments', fn(string $lang): array => require __DIR__ . '/../../_lang/' . $lang . '/comments.php');

            return $translator;
        });

        $container->set(ArticleCommentStrategy::class, fn(Container $container): \S2\Cms\Model\Comment\ArticleCommentStrategy => new ArticleCommentStrategy(
            $container->get(DbLayer::class),
            $container->get(ArticleProvider::class),
            $container->get(CommentNotifier::class),
        ), [CommentStrategyInterface::class]);

        $container->set(AuthProvider::class, fn(Container $container): \S2\Cms\Model\AuthProvider => new AuthProvider(
            $container->get(DbLayer::class),
            $container->getStringParameter('cookie_name'),
        ));

        $container->set(UserProvider::class, fn(Container $container): \S2\Cms\Model\User\UserProvider => new UserProvider(
            $container->get(DbLayer::class),
        ));

        $container->set(SpamDetectorInterface::class, function (Container $container): \S2\Cms\Comment\AkismetProxy {
            $provider = $container->get(DynamicConfigProvider::class);
            return new AkismetProxy(
                $container->get(HttpClient::class),
                $container->get(UrlBuilder::class),
                $container->get(LoggerInterface::class),
                $provider->getStringProxy('S2_AKISMET_KEY'),
            );
        }, ['dynamic_config_dependent']);

        $container->set(SpamDecisionProviderInterface::class, fn(Container $container): \S2\Cms\Comment\SpamDecisionProvider => new SpamDecisionProvider(
            $container->get(SpamDetectorInterface::class),
        ));

        $container->set(CommentController::class, function (Container $container): \S2\Cms\Controller\CommentController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new CommentController(
                $container->get(AuthProvider::class),
                $container->get(UserProvider::class),
                $container->get(ArticleCommentStrategy::class),
                $container->get('comments_translator'),
                $container->get(UrlBuilder::class),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $container->get(LoggerInterface::class),
                $container->get(CommentMailer::class),
                $container->get(SpamDecisionProviderInterface::class),
                $provider->getBoolProxy('S2_ENABLED_COMMENTS'),
                $provider->getBoolProxy('S2_PREMODERATION'),
            );
        }, ['dynamic_config_dependent']);

        $container->set(CommentSentController::class, fn(Container $container): \S2\Cms\Controller\CommentSentController => new CommentSentController(
            $container->get(AuthProvider::class),
            $container->get(UserProvider::class),
            $container->get('comments_translator'),
            $container->get(UrlBuilder::class),
            $container->get(HtmlTemplateProvider::class),
            $container->get(CommentMailer::class),
            ...$container->getByTag(CommentStrategyInterface::class)
        ), ['dynamic_config_dependent']);

        $container->set(CommentUnsubscribeController::class, fn(Container $container): \S2\Cms\Controller\CommentUnsubscribeController => new CommentUnsubscribeController(
            $container->get('comments_translator'),
            $container->get(HtmlTemplateProvider::class),
            ...$container->getByTag(CommentStrategyInterface::class)
        ));

        $container->set(ArticleRssStrategy::class, function (Container $container): \S2\Cms\Model\Article\ArticleRssStrategy {
            $provider = $container->get(DynamicConfigProvider::class);
            return new ArticleRssStrategy(
                $container->get(ArticleProvider::class),
                $container->get(UrlBuilder::class),
                $container->get('translator'),
                $provider->getStringProxy('S2_SITE_NAME'),
            );
        });

        $container->set(RssController::class, function (Container $container): \S2\Cms\Controller\RssController {
            $provider = $container->get(DynamicConfigProvider::class);
            return new RssController(
                $container->get(ArticleRssStrategy::class),
                $container->get(UrlBuilder::class),
                $container->get('strict_viewer'),
                $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
                $container->getStringParameter('base_path'),
                $container->getStringParameter('base_url'),
                $container->getStringParameter('version'),
                $provider->getStringProxy('S2_WEBMASTER'),
            );
        });

        $container->set(Sitemap::class, function (Container $container): \S2\Cms\Controller\Sitemap {
            $provider = $container->get(DynamicConfigProvider::class);
            return new Sitemap(
                $container->get(DbLayer::class),
                $container->get(ArticleProvider::class),
                $container->get(UrlBuilder::class),
                $container->get('strict_viewer'),
                $provider->getBoolProxy('S2_USE_HIERARCHY'),
            );
        });
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(NotFoundEvent::class, function (NotFoundEvent $event) use ($container): void {
            $redirectDetector = $container->get(RedirectDetector::class);
            $redirectResponse = $redirectDetector->getRedirectResponse($event->request);
            if ($redirectResponse !== null) {
                $event->response = $redirectResponse;
                return;
            }

            if (!$event->response instanceof \Symfony\Component\HttpFoundation\Response) {
                $controller      = $container->get(NotFoundController::class);
                $event->response = $controller->handle($event->request);
            }
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_CREATED, function (TemplateEvent $event) use ($container): void {
            $template = $event->htmlTemplate;

            if ($template->hasPlaceholder('<!-- s2_last_articles -->')) {
                $articleProvider = $container->get(ArticleProvider::class);
                $template->registerPlaceholder('<!-- s2_last_articles -->', $articleProvider->lastArticlesPlaceholder(5));
            }

            if ($template->hasPlaceholder('<!-- s2_tags_list -->')) {
                $tagsProvider = $container->get(TagsProvider::class);
                $tagsList     = $tagsProvider->tagsList();

                if (\count($tagsList) > 0) {
                    $viewer = $container->get(Viewer::class);
                    $template->registerPlaceholder('<!-- s2_tags_list -->', $viewer->render('tags_list', [
                        'tags' => $tagsList,
                    ]));
                } else {
                    $template->registerPlaceholder('<!-- s2_tags_list -->', '');
                }
            }

            if ($template->hasPlaceholder('<!-- s2_last_comments -->')) {
                $commentProvider = $container->get(CommentProvider::class);
                $lastComments    = $commentProvider->lastArticleComments();

                if (\count($lastComments) > 0) {
                    $viewer = $container->get(Viewer::class);
                    /** @var TranslatorInterface $translator */
                    $translator = $container->get('translator');
                    $template->registerPlaceholder('<!-- s2_last_comments -->', $viewer->render('menu_comments', [
                        'title' => $translator->trans('Last comments'),
                        'menu'  => $lastComments,
                    ]));
                } else {
                    $template->registerPlaceholder('<!-- s2_last_comments -->', '');
                }
            }

            if ($template->hasPlaceholder('<!-- s2_last_discussions -->')) {
                $commentProvider = $container->get(CommentProvider::class);
                $lastDiscussions = $commentProvider->lastDiscussions();

                if (\count($lastDiscussions) > 0) {
                    $viewer = $container->get(Viewer::class);
                    /** @var TranslatorInterface $translator */
                    $translator = $container->get('translator');
                    $template->registerPlaceholder('<!-- s2_last_discussions -->', $viewer->render('menu_block', [
                        'title' => $translator->trans('Last discussions'),
                        'menu'  => $lastDiscussions,
                    ]));
                } else {
                    $template->registerPlaceholder('<!-- s2_last_discussions -->', '');
                }
            }
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_PRE_REPLACE, function (TemplateEvent $event) use ($container): void {
            $s2DebugOutput = '';
            if ($container->getBoolParameter('show_queries')) {
                $pdo     = $container->getIfInstantiated(\PDO::class);
                $pdoLogs = $pdo instanceof PDO ? $pdo->cleanLogs() : [];

                $viewer        = $container->get(Viewer::class);
                $s2DebugOutput = $viewer->render('debug_queries', [
                    'saved_queries' => $pdoLogs,
                ]);
            }

            $event->htmlTemplate->registerPlaceholder('<!-- s2_debug -->', $s2DebugOutput);
        });

        $eventDispatcher->addListener(TemplateFinalReplaceEvent::class, function (TemplateFinalReplaceEvent $event) use ($container): void {
            $content = '';
            if ($container->getBoolParameter('debug') || defined('S2_SHOW_TIME')) {
                $viewer = $container->get(Viewer::class);

                /** @var TranslatorInterface $translator */
                $translator = $container->get('translator');

                $pdo           = $container->getIfInstantiated(\PDO::class);
                $executionTime = microtime(true) - $container->getFloatParameter('boot_timestamp');
                $content       = '<span class="technical-data">' . \sprintf(
                    $translator->trans('Performance info'),
                    $viewer->numberFormat($executionTime * 1000.0, true, 1),
                    $pdo instanceof PDO ? $pdo->getQueryCount() : 0
                ) . '</span>';
            }

            $event->replace('<!-- s2_querytime -->', $content);
        }, -256);
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
        $configProvider = $container->get(DynamicConfigProvider::class);
        $favoriteUrl    = $configProvider->getStringProxy('S2_FAVORITE_URL')->get();
        $tagsUrl        = $configProvider->getStringProxy('S2_TAGS_URL')->get();

        $routes->add('rss', new Route(
            '/rss.xml',
            ['_controller' => RssController::class],
            methods: ['GET']
        ));
        $routes->add('sitemap', new Route(
            '/sitemap.xml',
            ['_controller' => Sitemap::class],
            methods: ['GET']
        ));
        $routes->add('favorite', new Route(
            '/' . $favoriteUrl . '{slash</?>}',
            ['_controller' => PageFavorite::class],
            options: ['utf8' => true],
            methods: ['GET']
        ));
        $routes->add('tags', new Route(
            '/' . $tagsUrl . '{slash</?>}',
            ['_controller' => PageTags::class],
            options: ['utf8' => true],
            methods: ['GET']
        ));
        $routes->add('tag', new Route(
            '/' . $tagsUrl . '/{name}{slash</?>}',
            ['_controller' => PageTag::class],
            options: ['utf8' => true],
            methods: ['GET']
        ));
        $routes->add('common', new Route(
            '/{path<.*>}',
            ['_controller' => PageCommon::class],
            methods: ['GET']
        ), -1024); // Generic content fallback must remain below extension routes.
        $routes->add('comment_sent', new Route(
            '/comment_sent',
            ['_controller' => CommentSentController::class],
            methods: ['GET']
        ));
        $routes->add('comment_unsubscribe', new Route(
            '/comment_unsubscribe',
            ['_controller' => CommentUnsubscribeController::class],
            methods: ['GET']
        ));
        $routes->add('comment', new Route(
            '/{path<.*>}',
            ['_controller' => CommentController::class],
            methods: ['POST']
        ), -1024); // Generic comment fallback must remain below extension routes.
    }
}
