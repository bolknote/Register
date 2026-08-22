<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register;

use Register\Author\AuthorProfileRepository;
use Register\Ai\AiClient;
use Register\Ai\AiSettings;
use Register\Comment\ContentCommentRenderer;
use Register\Backup\BackupEncryptionKeyProvider;
use Register\Backup\BackupContributorInterface;
use Register\Backup\BackupEncryptor;
use Register\Backup\BackupManager;
use Register\Backup\BackupQueueHandler;
use Register\Backup\BackupScheduler;
use Register\Backup\DatabaseSnapshotter;
use Register\Comment\CommentRepository;
use Register\Comment\CommentImportService;
use Register\Comment\CommentPresentationEnricherInterface;
use Register\Comment\ContentCommentNotifier;
use Register\Comment\ContentCommentStrategy;
use Register\Comment\ContentCommentTargetResolver;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentDetailsRepository;
use Register\Content\ContentPublicationScheduler;
use Register\Content\ContentPublicationQueueHandler;
use Register\Content\ContentRepository;
use Register\Content\Admin\ContentRevisionService;
use Register\Content\ContentSourceInterface;
use Register\Content\ContentStatisticsRepository;
use Register\Content\ContentType;
use Register\Content\Controller\ContentSitemapController;
use Register\Content\Controller\RobotsTxtController;
use Register\Content\PageContentSource;
use Register\Content\TagRepository;
use Register\Live\LiveFragmentRenderer;
use Register\Live\LiveUpdateContext;
use Register\Live\LiveUpdateController;
use Register\Live\LiveUpdateRepository;
use Register\Module\BaseModuleInstaller;
use Register\Module\BaseModuleRegistry;
use Register\Module\Blog\Model\PostFeedRenderer;
use Register\Offline\OfflineCachePolicy;
use Register\Schema\SchemaManager;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlAliasRepository;
use Register\Url\ContentUrlGenerator;
use Register\Url\IcuTransliterator;
use Register\Url\PortableAsciiTransliterator;
use Register\Url\ReservedRouteRegistry;
use Register\Url\SlugGenerator;
use Register\Url\UniqueSlugGenerator;
use S2\Cms\Asset\AssetPack;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Framework\RoutingModuleInterface;
use S2\Cms\Framework\StatefulServiceInterface;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\Controller\Comment\CommentStrategyInterface;
use S2\Cms\Mail\CommentMailer;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\AuthProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Security\Audit\SecurityAuditLogger;
use S2\Cms\Template\HtmlTemplateProvider;
use S2\Cms\Template\TemplateAssetEvent;
use S2\Cms\Template\TemplateEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Registers services owned by the Register product rather than the reusable S2 foundation.
 */
readonly class ProductModule implements ContainerModuleInterface, ContainerAwareListenerModuleInterface, RoutingModuleInterface
{
    public function __construct(private BaseModuleRegistry $baseModuleRegistry)
    {
    }

    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(BaseModuleRegistry::class, $this->baseModuleRegistry);
        $container->set(AiSettings::class, static fn(Container $container): AiSettings => new AiSettings(
            $container->get(DynamicConfigProvider::class),
        ));
        $container->set('ai_token_cache', static fn(Container $container): FilesystemAdapter => new FilesystemAdapter(
            'ai_tokens',
            0,
            $container->getStringParameter('cache_dir'),
        ));
        $container->set(AiClient::class, static fn(Container $container): AiClient => new AiClient(
            $container->get(HttpClient::class),
            $container->get(AiSettings::class),
            $container->get('ai_token_cache'),
        ));
        $container->set(DatabaseSnapshotter::class, static fn(Container $container): DatabaseSnapshotter => new DatabaseSnapshotter(
            $container->get(\PDO::class),
            $container->getStringParameter('db_type'),
            $container->getStringParameter('db_host'),
            $container->getStringParameter('db_name'),
            $container->getStringParameter('db_username'),
            $container->getStringParameter('db_password'),
        ));
        $container->set(BackupEncryptionKeyProvider::class, static function (Container $container): BackupEncryptionKeyProvider {
            $configuredSecret = $container->getNullableStringParameter('backup_encryption_key');
            if (!\is_string($configuredSecret) || \strlen($configuredSecret) < BackupEncryptionKeyProvider::KEY_BYTES) {
                $staticSecret = $container->getNullableStringParameter('antispam_secret');
                $configuredSecret = \is_string($staticSecret)
                    && \strlen($staticSecret) >= BackupEncryptionKeyProvider::KEY_BYTES
                    ? $staticSecret
                    : '';
            }

            return new BackupEncryptionKeyProvider(
                $configuredSecret,
                $container->getNullableStringParameter('backup_recipient_public_key'),
            );
        });
        $container->set(BackupEncryptor::class, static fn(Container $container): BackupEncryptor => new BackupEncryptor(
            $container->get(BackupEncryptionKeyProvider::class),
        ));
        $container->set(BackupManager::class, static fn(Container $container): BackupManager => new BackupManager(
            $container->get(DatabaseSnapshotter::class),
            $container->get(BackupEncryptor::class),
            $container->get(\Psr\Log\LoggerInterface::class),
            $container->get(SecurityAuditLogger::class),
            $container->getStringParameter('backup_dir'),
            $container->getStringParameter('image_dir'),
            $container->getIntParameter('backup_retention'),
            $container->getStringParameter('version'),
            ...$container->getByTag(BackupContributorInterface::class),
        ));
        $container->set(BackupScheduler::class, static fn(Container $container): BackupScheduler => new BackupScheduler(
            $container->get(BackupManager::class),
            $container->get(\Psr\Log\LoggerInterface::class),
            $container->getBoolParameter('backup_enabled'),
        ));
        $container->set(BackupQueueHandler::class, static fn(Container $container): BackupQueueHandler => new BackupQueueHandler(
            $container->get(BackupManager::class),
            $container->get(QueuePublisher::class),
            $container->getBoolParameter('backup_enabled'),
        ), [QueueHandlerInterface::class]);
        $container->set(ContentUrlGenerator::class, static fn(Container $container): ContentUrlGenerator => new ContentUrlGenerator(
            $container->get(DbLayer::class),
            $container->get(UrlBuilder::class),
        ));
        $container->set(ContentUrlAliasRepository::class, static fn(Container $container): ContentUrlAliasRepository => new ContentUrlAliasRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(PageContentSource::class, static fn(Container $container): PageContentSource => new PageContentSource(
            $container->get(DbLayer::class),
            $container->get(ContentUrlGenerator::class),
        ), [ContentSourceInterface::class]);
        $container->set(ContentRepository::class, static fn(Container $container): ContentRepository => new ContentRepository(
            ...$container->getByTag(ContentSourceInterface::class),
        ));
        $container->set(AuthorProfileRepository::class, static fn(Container $container): AuthorProfileRepository => new AuthorProfileRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(LiveUpdateRepository::class, static fn(Container $container): LiveUpdateRepository => new LiveUpdateRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(LiveUpdateContext::class, static fn(Container $container): LiveUpdateContext => new LiveUpdateContext(
            $container->get(LiveUpdateRepository::class),
        ), [StatefulServiceInterface::class]);
        $container->set(ContentChangeDispatcher::class, static fn(Container $container): ContentChangeDispatcher => new ContentChangeDispatcher(
            $container->get(DbLayer::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->get(LiveUpdateRepository::class),
        ), [StatefulServiceInterface::class]);
        $container->set(ContentPublicationScheduler::class, static fn(Container $container): ContentPublicationScheduler => new ContentPublicationScheduler(
            $container->get(DbLayer::class),
            $container->get(\PDO::class),
            $container->get(ContentChangeDispatcher::class),
        ));
        $container->set(ContentPublicationQueueHandler::class, static fn(Container $container): ContentPublicationQueueHandler => new ContentPublicationQueueHandler(
            $container->get(ContentPublicationScheduler::class),
            $container->get(QueuePublisher::class),
        ), [QueueHandlerInterface::class]);
        $container->set(ContentStatisticsRepository::class, static fn(Container $container): ContentStatisticsRepository => new ContentStatisticsRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ContentRevisionService::class, new ContentRevisionService());
        $container->set(ContentSitemapController::SERVICE_ID, static fn(Container $container): ContentSitemapController => new ContentSitemapController(
            $container->get(ContentRepository::class),
            $container->get(ContentUrlGenerator::class),
            $container->get('strict_viewer'),
            ContentType::PAGE,
            ContentType::POST,
        ));
        $container->set(RobotsTxtController::class, static fn(Container $container): RobotsTxtController => new RobotsTxtController(
            $container->get(ContentUrlGenerator::class),
            $container->getStringParameter('base_path'),
        ));
        $container->set(TagRepository::class, static fn(Container $container): TagRepository => new TagRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ContentDetailsRepository::class, static fn(Container $container): ContentDetailsRepository => new ContentDetailsRepository(
            $container->get(ContentRepository::class),
            $container->get(AuthorProfileRepository::class),
            $container->get(TagRepository::class),
        ));
        $container->set(CommentRepository::class, static fn(Container $container): CommentRepository => new CommentRepository(
            $container->get(DbLayer::class),
            $container->get(LiveUpdateRepository::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
        ));
        $container->set(CommentImportService::class, static fn(Container $container): CommentImportService => new CommentImportService(
            $container->get(CommentRepository::class),
        ));
        $container->set(ContentCommentRenderer::class, static fn(Container $container): ContentCommentRenderer => new ContentCommentRenderer(
            $container->get(DbLayer::class),
            $container->get(\S2\Cms\Model\Comment\CommentThreadRenderer::class),
            $container->get(\S2\Cms\Model\AuthProvider::class),
            ...$container->getByTag(CommentPresentationEnricherInterface::class),
        ));
        $container->set(LiveFragmentRenderer::class, static fn(Container $container): LiveFragmentRenderer => new LiveFragmentRenderer(
            $container->get(HtmlTemplateProvider::class),
        ));
        $container->set(LiveUpdateController::class, static fn(Container $container): LiveUpdateController => new LiveUpdateController(
            $container->get(LiveUpdateRepository::class),
            $container->get(PostFeedRenderer::class),
            $container->get(ContentCommentRenderer::class),
            $container->get(ContentRepository::class),
            $container->get(LiveFragmentRenderer::class),
        ));
        $container->set(ContentCommentTargetResolver::class, static fn(Container $container): ContentCommentTargetResolver => new ContentCommentTargetResolver(
            $container->get(DbLayer::class),
            $container->get(ArticleProvider::class),
        ));
        $container->set(ContentCommentNotifier::class, static fn(Container $container): ContentCommentNotifier => new ContentCommentNotifier(
            $container->get(CommentRepository::class),
            $container->get(\Register\Comment\CommentSubscriptionService::class),
            $container->get(ContentRepository::class),
            $container->get(ContentUrlGenerator::class),
            $container->get(CommentMailer::class),
        ));
        $container->set(ContentCommentStrategy::PAGE_SERVICE_ID, static fn(Container $container): ContentCommentStrategy => new ContentCommentStrategy(
            ContentType::PAGE,
            $container->get(CommentRepository::class),
            $container->get(ContentCommentTargetResolver::class),
            $container->get(ContentCommentNotifier::class),
        ), [CommentStrategyInterface::class]);
        $container->set(ContentCommentStrategy::POST_SERVICE_ID, static fn(Container $container): ContentCommentStrategy => new ContentCommentStrategy(
            ContentType::POST,
            $container->get(CommentRepository::class),
            $container->get(ContentCommentTargetResolver::class),
            $container->get(ContentCommentNotifier::class),
        ), [CommentStrategyInterface::class]);
        $container->set(
            BaseModuleInstaller::class,
            fn(Container $container): BaseModuleInstaller => new BaseModuleInstaller(
                $container->get(BaseModuleRegistry::class),
            ),
        );
        $container->set(SchemaManager::class, fn(Container $container): SchemaManager => new SchemaManager(
            $container->get(DbLayer::class),
            $container,
            $container->get(BaseModuleInstaller::class),
        ));
        $container->set(SlugGenerator::class, new SlugGenerator(
            new PortableAsciiTransliterator(),
            IcuTransliterator::create(),
        ));
        $container->set(UniqueSlugGenerator::class, static fn(Container $container): UniqueSlugGenerator => new UniqueSlugGenerator(
            $container->get(SlugGenerator::class),
        ));
        $container->set(ReservedRouteRegistry::class, static function (Container $container): ReservedRouteRegistry {
            $provider = $container->get(DynamicConfigProvider::class);

            return new ReservedRouteRegistry(
                $provider->getStringProxy('S2_TAGS_URL'),
                $provider->getStringProxy('S2_FAVORITE_URL'),
            );
        });
        $container->set(ContentSlugService::class, static fn(Container $container): ContentSlugService => new ContentSlugService(
            $container->get(DbLayer::class),
            $container->get(UniqueSlugGenerator::class),
            $container->get(ReservedRouteRegistry::class),
            $container->get(ContentUrlAliasRepository::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $basePath = rtrim($container->getStringParameter('base_path'), '/');
            $publicRoot = $container->getStringParameter('public_root_dir');
            $versionedAsset = static function (string $path) use ($basePath, $publicRoot): string {
                $modifiedAt = \filemtime($publicRoot . ltrim($path, '/'));
                if ($modifiedAt === false) {
                    throw new \LogicException(\sprintf('Unable to read the modification time of "%s".', $path));
                }

                return $basePath . $path . '?v=' . $modifiedAt;
            };
            $event->assetPack
                ->addCss($versionedAsset('/_assets/register/comment-editor.css'))
                ->addCss($basePath . '/_assets/register/offline.css')
                ->addCss($versionedAsset('/_assets/register/partial-navigation.css'))
                ->addJs($versionedAsset('/_assets/register/comment-editor.js'), [AssetPack::OPTION_DEFER])
                ->addJs($versionedAsset('/_assets/register/offline.js'), [AssetPack::OPTION_DEFER])
                ->addJs($versionedAsset('/_assets/register/live-updates.js'), [AssetPack::OPTION_DEFER])
                ->addJs($versionedAsset('/_assets/register/partial-navigation.js'), [AssetPack::OPTION_DEFER])
            ;
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_PRE_REPLACE, static function (TemplateEvent $event) use ($container): void {
            $basePath = rtrim($container->getStringParameter('base_path'), '/');
            $request = $container->get(RequestStack::class)->getCurrentRequest();
            $allowsInitialSeed = $request instanceof Request && OfflineCachePolicy::allowsInitialSeed(
                $request,
                $container->get(AuthProvider::class)->hasAuthenticatedPublicSession($request),
            );
            /** @var TranslatorInterface $translator */
            $translator = $container->get('translator');
            $event->htmlTemplate->addMetaTag(sprintf(
                '<meta name="register-offline" data-worker="%s/service-worker.js" data-scope="%s/"'
                    . ' data-seed="%s" data-warning="%s" data-syncing="%s" data-reload="%s">',
                s2_htmlencode($basePath),
                s2_htmlencode($basePath),
                $allowsInitialSeed ? '1' : '0',
                s2_htmlencode($translator->trans('Offline cache warning')),
                s2_htmlencode($translator->trans('Offline cache syncing')),
                s2_htmlencode($translator->trans('Reload current page')),
            ));

            $context = $container->get(LiveUpdateContext::class);
            $cursor  = $context->cursor();
            $regions = $context->regions();
            if ($cursor === null || $regions === []) {
                return;
            }

            $event->htmlTemplate->addMetaTag(sprintf(
                '<meta name="register-live-updates" data-endpoint="%s" data-cursor="%d" data-regions="%s">',
                s2_htmlencode($container->get(UrlBuilder::class)->link('/_live')),
                $cursor,
                s2_htmlencode(json_encode($regions, JSON_THROW_ON_ERROR)),
            ));
        });
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->add('register_live_updates', new Route(
            '/_live',
            ['_controller' => LiveUpdateController::class],
            methods: ['GET'],
        ));
    }

}
