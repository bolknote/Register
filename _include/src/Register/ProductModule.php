<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register;

use Register\Author\AuthorProfileRepository;
use Register\Auth\CommentNotificationRepository;
use Register\Auth\PublicAuthRepository;
use Register\Auth\MagicLinkService;
use Register\Auth\MagicLinkRateLimiter;
use Register\Auth\PublicAuthMailer;
use Register\Auth\PublicAuthSettings;
use Register\Auth\PublicAuthController;
use Register\Auth\PublicAuthFormToken;
use Register\Auth\PublicAuthRenderer;
use Register\Auth\PublicOAuthClient;
use Register\Auth\PublicSessionManager;
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
use Register\Content\PublicationMetadataGenerator;
use Register\Content\Admin\ContentRevisionService;
use Register\Content\ContentSourceInterface;
use Register\Content\ContentStatisticsRepository;
use Register\Content\ContentViewRepository;
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
use Register\Module\Blog\Model\SiteHeaderRenderer;
use Register\Offline\OfflineCachePolicy;
use Register\Schema\ContentMediaSchemaMigration;
use Register\Schema\PublicAuthSchemaMigration;
use Register\Schema\SchemaManager;
use Register\Schema\SchemaMigrationInterface;
use Register\Schema\SchemaMigrator;
use Register\Schema\VisitorUserSchemaMigration;
use Register\Schema\SocialEngagementSchemaMigration;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlAliasRepository;
use Register\Url\ContentUrlGenerator;
use Register\Url\IcuTransliterator;
use Register\Url\PortableAsciiTransliterator;
use Register\Url\ReservedRouteRegistry;
use Register\Url\SlugGenerator;
use Register\Url\UniqueSlugGenerator;
use Register\Core\Asset\AssetPack;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Framework\RoutingModuleInterface;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\HttpClient\HttpClient;
use Register\Core\Controller\Comment\CommentStrategyInterface;
use Register\Core\Controller\Comment\PendingEmailCommentServiceInterface;
use Register\Core\Mail\CommentMailer;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Register\Core\Template\TemplateAssetEvent;
use Register\Core\Template\TemplateEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Registers services owned by the Register product rather than the reusable Register foundation.
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
        $container->set(ContentViewRepository::class, static fn(Container $container): ContentViewRepository => new ContentViewRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(PublicAuthSettings::class, static fn(Container $container): PublicAuthSettings => new PublicAuthSettings(
            $container->get(DynamicConfigProvider::class),
        ));
        $container->set(PublicAuthFormToken::class, static function (Container $container): PublicAuthFormToken {
            $provider = $container->get(DynamicConfigProvider::class);

            return new PublicAuthFormToken($provider->getStringProxy('REGISTER_ANTISPAM_SECRET'));
        });
        $container->set(PublicSessionManager::class, static fn(Container $container): PublicSessionManager => new PublicSessionManager(
            $container->get(DbLayer::class),
            $container->get(\Register\Core\Model\LoginRateLimiter::class),
            $container->get(SecurityAuditLogger::class),
            $container->get('translator'),
            $container->getStringParameter('base_path'),
            $container->getStringParameter('base_url'),
            $container->getStringParameter('cookie_name'),
            $container->getBoolParameter('force_admin_https'),
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
        $container->set(PublicationMetadataGenerator::class, static fn(Container $container): PublicationMetadataGenerator => new PublicationMetadataGenerator(
            $container->get(AiClient::class),
            $container->get(AiSettings::class),
            $container->get(\Psr\Log\LoggerInterface::class),
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
        $container->set(PublicAuthRepository::class, static fn(Container $container): PublicAuthRepository => new PublicAuthRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(CommentNotificationRepository::class, static fn(Container $container): CommentNotificationRepository => new CommentNotificationRepository(
            $container->get(DbLayer::class),
            $container->get(PublicAuthRepository::class),
        ));
        $container->set(PublicOAuthClient::class, static fn(Container $container): PublicOAuthClient => new PublicOAuthClient(
            $container->get(HttpClient::class),
            $container->get(PublicAuthSettings::class),
            $container->get(PublicAuthRepository::class),
            $container->get(UrlBuilder::class),
        ));
        $container->set(PublicAuthRenderer::class, static fn(Container $container): PublicAuthRenderer => new PublicAuthRenderer(
            $container->get(Viewer::class),
            $container->get(UrlBuilder::class),
            $container->get(AuthProvider::class),
            $container->get(PublicAuthSettings::class),
            $container->get(PublicAuthFormToken::class),
            $container->get(CommentNotificationRepository::class),
            $container->get(LiveUpdateContext::class),
        ));
        $container->set(PublicAuthMailer::class, static function (Container $container): PublicAuthMailer {
            $provider = $container->get(DynamicConfigProvider::class);

            return new PublicAuthMailer(
                $container->get('translator'),
                $provider->getStringProxy('REGISTER_SITE_NAME'),
                $provider->getStringProxy('REGISTER_WEBMASTER'),
                $provider->getStringProxy('REGISTER_WEBMASTER_EMAIL'),
            );
        });
        $container->set(MagicLinkRateLimiter::class, static fn(Container $container): MagicLinkRateLimiter => new MagicLinkRateLimiter(
            $container->get(DbLayer::class),
            $container->get(\Register\Core\Comment\Antispam\SpamIdentityHasher::class),
            $container->get(\Psr\Log\LoggerInterface::class),
        ));
        $container->set(CommentImportService::class, static fn(Container $container): CommentImportService => new CommentImportService(
            $container->get(CommentRepository::class),
        ));
        $container->set(ContentCommentRenderer::class, static fn(Container $container): ContentCommentRenderer => new ContentCommentRenderer(
            $container->get(DbLayer::class),
            $container->get(\Register\Core\Model\Comment\CommentThreadRenderer::class),
            $container->get(\Register\Core\Model\AuthProvider::class),
            $container->get(CommentNotificationRepository::class),
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
            $container->get(SiteHeaderRenderer::class),
            $container->get(PublicAuthRenderer::class),
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
        $container->set(MagicLinkService::class, static fn(Container $container): MagicLinkService => new MagicLinkService(
            $container->get(PublicAuthSettings::class),
            $container->get(PublicAuthRepository::class),
            $container->get(PublicAuthMailer::class),
            $container->get(UrlBuilder::class),
            $container->get(MagicLinkRateLimiter::class),
            $container->get('translator'),
            $container->get(\Register\Module\VisitorIdentity\VisitorIdentityManager::class),
            ...$container->getByTag(CommentStrategyInterface::class),
        ), [PendingEmailCommentServiceInterface::class]);
        $container->set(
            PendingEmailCommentServiceInterface::class,
            static fn(Container $container): PendingEmailCommentServiceInterface => $container->get(MagicLinkService::class),
        );
        $container->set(PublicAuthController::class, static fn(Container $container): PublicAuthController => new PublicAuthController(
            $container->get(AuthProvider::class),
            $container->get(PublicSessionManager::class),
            $container->get(PublicAuthRepository::class),
            $container->get(PublicOAuthClient::class),
            $container->get(MagicLinkService::class),
            $container->get(PublicAuthRenderer::class),
            $container->get(PublicAuthFormToken::class),
            $container->get(CommentNotificationRepository::class),
            $container->get(ContentUrlGenerator::class),
            $container->get(HtmlTemplateProvider::class),
            $container->get(UrlBuilder::class),
            $container->get('translator'),
            $container->get(\Psr\Log\LoggerInterface::class),
            $container->get(\Register\Module\VisitorIdentity\VisitorIdentityManager::class),
        ));
        $container->set(
            BaseModuleInstaller::class,
            fn(Container $container): BaseModuleInstaller => new BaseModuleInstaller(
                $container->get(BaseModuleRegistry::class),
            ),
        );
        $container->set(
            ContentMediaSchemaMigration::class,
            new ContentMediaSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            PublicAuthSchemaMigration::class,
            new PublicAuthSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            VisitorUserSchemaMigration::class,
            new VisitorUserSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            SocialEngagementSchemaMigration::class,
            new SocialEngagementSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(SchemaMigrator::class, fn(Container $container): SchemaMigrator => new SchemaMigrator(
            $container->get(DbLayer::class),
            $container->getByTag(SchemaMigrationInterface::class),
        ));
        $container->set(SchemaManager::class, fn(Container $container): SchemaManager => new SchemaManager(
            $container->get(DbLayer::class),
            $container,
            $container->get(BaseModuleInstaller::class),
            $container->get(SchemaMigrator::class),
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
                $provider->getStringProxy('REGISTER_TAGS_URL'),
                $provider->getStringProxy('REGISTER_FAVORITE_URL'),
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
                ->addCss($versionedAsset('/_assets/register/public-auth.css'))
                ->addJs($versionedAsset('/_assets/register/public-auth.js'), [AssetPack::OPTION_DEFER])
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
                register_htmlencode($basePath),
                register_htmlencode($basePath),
                $allowsInitialSeed ? '1' : '0',
                register_htmlencode($translator->trans('Offline cache warning')),
                register_htmlencode($translator->trans('Offline cache syncing')),
                register_htmlencode($translator->trans('Reload current page')),
            ));

            $context = $container->get(LiveUpdateContext::class);
            $cursor  = $context->cursor();
            $regions = $context->regions();
            if ($cursor === null || $regions === []) {
                return;
            }

            $event->htmlTemplate->addMetaTag(sprintf(
                '<meta name="register-live-updates" data-endpoint="%s" data-cursor="%d" data-regions="%s">',
                register_htmlencode($container->get(UrlBuilder::class)->link('/_live')),
                $cursor,
                register_htmlencode(json_encode($regions, JSON_THROW_ON_ERROR)),
            ));
        });
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes): void
    {
        // Public service endpoints must win over the blog's catch-all content/comment routes.
        $authPriority = 2;

        $routes->add('register_public_auth', new Route(
            '/auth',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'page'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_public_auth_password', new Route(
            '/auth/password',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'password'],
            methods: ['POST'],
        ), $authPriority);
        $routes->add('register_public_auth_logout', new Route(
            '/auth/logout',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'logout'],
            methods: ['POST'],
        ), $authPriority);
        $routes->add('register_public_auth_email', new Route(
            '/auth/email',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'email'],
            methods: ['POST'],
        ), $authPriority);
        $routes->add('register_public_auth_email_callback', new Route(
            '/auth/email/callback',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'email_callback'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_public_auth_check_email', new Route(
            '/auth/check-email',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'check_email'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_public_auth_oauth_start', new Route(
            '/auth/oauth/{provider<vk|mail_ru|ok_ru|yandex>}',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'oauth_start'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_public_auth_oauth_callback', new Route(
            '/auth/oauth/{provider<vk|mail_ru|ok_ru|yandex>}/callback',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'oauth_callback'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_public_auth_unread', new Route(
            '/auth/unread',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'unread'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_live_updates', new Route(
            '/_live',
            ['_controller' => LiveUpdateController::class],
            methods: ['GET'],
        ));
    }

}
