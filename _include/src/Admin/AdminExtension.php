<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin;

use Register\Ai\Admin\AiEditorController;
use Register\Ai\Admin\AiImageLoader;
use Register\Ai\AiClient;
use Register\Ai\AiSettings;
use Register\Backup\Admin\BackupAdminController;
use Register\Backup\Admin\BackupToken;
use Register\Backup\Admin\DashboardBackupProvider;
use Register\Backup\BackupManager;
use Register\Backup\BackupScheduler;
use Register\Content\Admin\DashboardContentProvider;
use Register\Content\Admin\ContentBulkPublicationService;
use Register\Content\Admin\ContentRevisionService;
use Register\Content\ContentStatisticsRepository;
use Register\Content\ContentChangeDispatcher;
use Register\Comment\CommentRepository;
use Register\Module\BaseModuleRegistry;
use Register\Update\Admin\UpdateAdminConfigExtender;
use Register\Update\Admin\UpdateAdminController;
use Register\Update\Admin\UpdateToken;
use Register\Update\ArchiveCapabilities;
use Register\Update\GeneratedAssetCacheCleaner;
use Register\Update\MaintenanceMode;
use Register\Update\ReleaseArchiveExtractor;
use Register\Update\UpdateApplier;
use Register\Update\UpdateDirectoryResolver;
use Register\Update\UpdateManager;
use Register\Update\UpdatePlanner;
use Register\Update\UpdateStorage;
use Register\Url\ContentSlugService;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Database\TypeTransformer;
use Register\AdminYard\Form\FormControlFactoryInterface;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Core\Admin\Dashboard\DashboardBlockProviderInterface;
use Register\Core\Admin\Dashboard\DashboardConfigExtender;
use Register\Core\Admin\Dashboard\DashboardCompressionProvider;
use Register\Core\Admin\Dashboard\DashboardDatabaseProvider;
use Register\Core\Admin\Dashboard\DashboardEnvironmentProvider;
use Register\Core\Admin\Dashboard\DashboardPerformanceProvider;
use Register\Core\Admin\Dashboard\DashboardQueryProfilerProvider;
use Register\Core\Admin\Dashboard\DashboardSecurityProvider;
use Register\Core\Admin\Dashboard\DashboardStatProviderInterface;
use Register\Core\Admin\Dashboard\SystemStatusProviderInterface;
use Register\Core\Admin\Controller\CommentControllerFactory;
use Register\Core\Admin\Controller\BulkListActionController;
use Register\Core\Admin\Controller\SavedListViewController;
use Register\Core\Admin\Event\RedirectFromPublicEvent;
use Register\Core\Admin\Event\AdminAjaxControllerMapEvent;
use Register\Core\Admin\Profiler\QueryProfilerController;
use Register\Core\Admin\Profiler\QueryProfilerToken;
use Register\Core\Admin\Picture\PictureStorageQuota;
use Register\Core\Admin\Picture\PictureFileNameHelper;
use Register\Core\Admin\Picture\MediaConfigExtender;
use Register\Core\Admin\Picture\PictureManager;
use Register\Core\Admin\Picture\PictureReserveManager;
use Register\Core\Admin\Security\ReauthenticationAdminConfigExtender;
use Register\Core\Admin\WebAuthn\WebAuthnAdminConfigExtender;
use Register\Core\Admin\WebAuthn\WebAuthnAdminController;
use Register\Core\AdminYard\CustomMenuGeneratorEvent;
use Register\Core\AdminYard\BulkListActionProvider;
use Register\Core\AdminYard\CustomTemplateRendererEvent;
use Register\Core\AdminYard\CustomTemplateRenderer;
use Register\Core\AdminYard\Form\CustomFormControlFactory;
use Register\Core\AdminYard\Signal;
use Register\Core\AdminYard\SavedListViewManager;
use Register\Core\AdminYard\UserSettingStorage;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Comment\Antispam\SpamFeedbackService;
use Register\Core\Extensions\ExtensionManager;
use Register\Core\Extensions\ExtensionManagerAdapter;
use Register\Core\Framework\Container;
use Register\Core\Framework\ExtensionInterface;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Model\ArticleManager;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\AuthManager;
use Register\Comment\ContentCommentNotifier;
use Register\Core\Model\ExtensionCache;
use Register\Core\Model\PermissionChecker;
use Register\Core\Monitoring\RequestPerformanceInspector;
use Register\Core\Monitoring\QueryProfilerInspector;
use Register\Core\Monitoring\QueryProfilerLog;
use Register\Core\Monitoring\QueryProfilerState;
use Register\Core\Monitoring\RequestQueryProfiler;
use Register\Core\Model\TagsProvider;
use Register\Core\Pdo\DbLayer;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Register\Core\Security\Http\AdminMutationGuard;
use Register\Core\Security\Http\SameOriginRequestGuard;
use Register\Core\Security\Monitoring\SecurityAlertDetector;
use Register\Core\Security\WebAuthn\RecoveryCodeRepository;
use Register\Core\Security\WebAuthn\WebAuthnChallengeRepository;
use Register\Core\Security\WebAuthn\WebAuthnCredentialRepository;
use Register\Core\Security\WebAuthn\WebAuthnService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

class AdminExtension implements ExtensionInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(FormControlFactoryInterface::class, fn(Container $container): \Register\Core\AdminYard\Form\CustomFormControlFactory => new CustomFormControlFactory(
            $container->get(Translator::class)
        ));

        // AdminYard services
        $container->set(TypeTransformer::class, fn(Container $_container): \Register\AdminYard\Database\TypeTransformer => new TypeTransformer());

        $container->set(PdoDataProvider::class, fn(Container $container): \Register\AdminYard\Database\PdoDataProvider => new PdoDataProvider(
            $container->get(\PDO::class),
            $container->get(TypeTransformer::class),
        ));

        $container->set(TranslationProvider::class, fn(Container $container): \Register\Core\Admin\TranslationProvider => new TranslationProvider($container->getStringParameter('root_dir')), [TranslationProviderInterface::class]);

        $container->set(Translator::class, function (Container $container): \Register\AdminYard\Translator {
            $provider = $container->get(DynamicConfigProvider::class);
            $language = $provider->getStringProxy('REGISTER_LANGUAGE')->get();

            // TODO move mapping somewhere
            $locale       = match ($language) {
                'Russian' => 'ru',
                'English' => 'en',
                default => throw new \LogicException('Unsupported language yet'),
            };
            $translations = [];
            foreach ($container->getByTag(TranslationProviderInterface::class) as $translationProvider) {
                $translations[] = $translationProvider->getTranslations($language, $locale);
            }

            return new Translator(array_merge(...$translations), $locale);
        });

        $container->set(FormFactory::class, fn(Container $container): \Register\AdminYard\Form\FormFactory => new FormFactory(
            $container->get(FormControlFactoryInterface::class),
            $container->get(Translator::class),
            $container->get(PdoDataProvider::class),
        ));

        $container->set(TemplateRenderer::class, fn(Container $container): \Register\Core\AdminYard\CustomTemplateRenderer => new CustomTemplateRenderer(
            $container->get(Translator::class),
            $container->get(DynamicConfigProvider::class),
            $container->get(PermissionChecker::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->getStringParameter('base_path'),
            $container->getStringParameter('root_dir'),
            $container->getStringParameter('content_image_directory'),
        ), [StatefulServiceInterface::class]);

        $container->set(SettingStorageInterface::class, fn(Container $container): \Register\Core\AdminYard\UserSettingStorage => new UserSettingStorage(
            $container->get(PermissionChecker::class),
            $container->get(DbLayer::class),
        ), [StatefulServiceInterface::class]);

        $container->set(SavedListViewManager::class, fn(Container $container): SavedListViewManager => new SavedListViewManager(
            $container->get(SettingStorageInterface::class),
        ));
        $container->set(SavedListViewController::class, fn(Container $container): SavedListViewController => new SavedListViewController(
            $container->get(SavedListViewManager::class),
            $container->get(Translator::class),
            $container->get(AdminMutationGuard::class),
        ));
        $container->set(BulkListActionProvider::class, fn(Container $container): BulkListActionProvider => new BulkListActionProvider(
            $container->get(SettingStorageInterface::class),
            $container->get(PermissionChecker::class),
        ));
        $container->set(ContentBulkPublicationService::class, fn(Container $container): ContentBulkPublicationService => new ContentBulkPublicationService(
            $container->get(DbLayer::class),
            $container->get(PermissionChecker::class),
            $container->get(ContentSlugService::class),
            $container->get(ContentChangeDispatcher::class),
        ));
        $container->set(BulkListActionController::class, fn(Container $container): BulkListActionController => new BulkListActionController(
            $container->get(BulkListActionProvider::class),
            $container->get(ContentBulkPublicationService::class),
            $container->get(AdminPanelFactory::class),
            $container->get(ContentChangeDispatcher::class),
            $container->get(RequestStack::class),
            $container->get(\PDO::class),
            $container->get(Translator::class),
            $container->get(\Psr\Log\LoggerInterface::class),
            $container->get(AdminMutationGuard::class),
        ));

        $container->set(ResourceProvider::class, fn(Container $container): \Register\Core\Admin\ResourceProvider => new ResourceProvider(
            $container->getStringParameter('root_dir'),
        ));

        $container->set(DynamicConfigFormBuilder::class, fn(Container $container): \Register\Core\Admin\DynamicConfigFormBuilder => new DynamicConfigFormBuilder(
            $container->get(PermissionChecker::class),
            $container->get(Translator::class),
            $container->get(TypeTransformer::class),
            $container->get(FormFactory::class),
            $container->get(TemplateRenderer::class),
            $container->get(ResourceProvider::class),
            $container->get(RequestStack::class),
            $container->get(SettingStorageInterface::class),
            $container->get(DynamicConfigProvider::class),
            $container->get(\Register\Core\Model\UrlBuilder::class),
            ...$container->getByTag(DynamicConfigFormExtenderInterface::class),
        ));

        $container->set(AdminConfigProvider::class, function (Container $container): \Register\Core\Admin\AdminConfigProvider {
            $dbType   = $container->getStringParameter('db_type');
            $dbPrefix = $container->getStringParameter('db_prefix');
            $provider = $container->get(DynamicConfigProvider::class);
            return new AdminConfigProvider(
                $container->get(PermissionChecker::class),
                $container->get(AuthManager::class),
                $container->get(DynamicConfigFormBuilder::class),
                $provider,
                $provider->getBoolProxy('REGISTER_ADMIN_CUT'),
                $container->get(Translator::class),
                $container->get(ArticleProvider::class),
                $container->get(ContentRevisionService::class),
                $container->get(ContentSlugService::class),
                $container->get(\Register\Url\ContentUrlGenerator::class),
                $container->get(TagsProvider::class),
                $container->get(\Register\Content\TagRepository::class),
                $container->get(ContentCommentNotifier::class),
                $container->get(CommentRepository::class),
                $container->get(\Register\Live\LiveUpdateRepository::class),
                $container->get(ExtensionCache::class),
                $container->get(ContentChangeDispatcher::class),
                $container->get(\Register\Content\ContentPublicationScheduler::class),
                $container->get(\Register\Content\PublicationMetadataGenerator::class),
                $container->get(CommentControllerFactory::class),
                $container->get(\Register\Core\Comment\Antispam\SpamMetricsRepository::class),
                $container->get(SecurityAuditLogger::class),
                $container->get(RequestStack::class),
                $dbType,
                $dbPrefix,
                ...$container->getByTag(AdminConfigExtenderInterface::class)
            );
        }, [StatefulServiceInterface::class]);

        $container->set(AiEditorController::class, fn(Container $container): AiEditorController => new AiEditorController(
            $container->get(AiClient::class),
            $container->get(AiSettings::class),
            $container->get(AdminConfigProvider::class),
            $container->get(SettingStorageInterface::class),
            $container->get(Translator::class),
            $container->get(AdminMutationGuard::class),
            $container->get(AiImageLoader::class),
        ));
        $container->set(AiImageLoader::class, fn(Container $container): AiImageLoader => new AiImageLoader(
            $container->getStringParameter('image_dir'),
            $container->getStringParameter('image_path'),
        ));

        $container->set(CommentControllerFactory::class, fn(Container $container): \Register\Core\Admin\Controller\CommentControllerFactory => new CommentControllerFactory(
            $container->get(SpamFeedbackService::class),
            $container->get(AdminMutationGuard::class),
            $container->get(CommentRepository::class),
            $container->get(\Register\Live\LiveUpdateRepository::class),
        ));

        $container->set(AdminPanelFactory::class, fn(Container $container): \Register\Core\Admin\AdminPanelFactory => new AdminPanelFactory($container));

        $container->set(PermissionChecker::class, fn(Container $_container): \Register\Core\Model\PermissionChecker => new PermissionChecker(), [StatefulServiceInterface::class]);

        $container->set(AuthManager::class, fn(Container $container): \Register\Core\Model\AuthManager => new AuthManager(
            $container->get(DbLayer::class),
            $container->get(PermissionChecker::class),
            $container->get(RequestStack::class),
            $container->get(TemplateRenderer::class),
            $container->get(Translator::class),
            $container->get(\Register\Core\Model\LoginRateLimiter::class),
            $container->get(SecurityAuditLogger::class),
            $container->getStringParameter('base_path'),
            $container->getStringParameter('base_url'),
            $container->getStringParameter('cookie_name'),
            $container->getBoolParameter('force_admin_https'),
        ));

        $container->set(
            AttestationStatementSupportManager::class,
            static fn(Container $_container): AttestationStatementSupportManager => AttestationStatementSupportManager::create(),
        );
        $container->set(
            SerializerInterface::class,
            static fn(Container $container): SerializerInterface => (new WebauthnSerializerFactory(
                $container->get(AttestationStatementSupportManager::class),
            ))->create(),
        );
        $container->set(WebAuthnCredentialRepository::class, static fn(Container $container): WebAuthnCredentialRepository => new WebAuthnCredentialRepository(
            $container->get(DbLayer::class),
            $container->get(SerializerInterface::class),
        ));
        $container->set(WebAuthnChallengeRepository::class, static fn(Container $container): WebAuthnChallengeRepository => new WebAuthnChallengeRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(RecoveryCodeRepository::class, static fn(Container $container): RecoveryCodeRepository => new RecoveryCodeRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(WebAuthnService::class, static fn(Container $container): WebAuthnService => new WebAuthnService(
            $container->get(DbLayer::class),
            $container->get(WebAuthnCredentialRepository::class),
            $container->get(WebAuthnChallengeRepository::class),
            $container->get(SerializerInterface::class),
            $container->get(AttestationStatementSupportManager::class),
            $container->getStringParameter('base_url'),
            $container->getBoolParameter('force_admin_https'),
        ));
        $container->set(WebAuthnAdminController::class, static fn(Container $container): WebAuthnAdminController => new WebAuthnAdminController(
            $container->get(WebAuthnService::class),
            $container->get(WebAuthnCredentialRepository::class),
            $container->get(RecoveryCodeRepository::class),
            $container->get(AuthManager::class),
            $container->get(PermissionChecker::class),
            $container->get(\Register\Core\Model\LoginRateLimiter::class),
            $container->get(Translator::class),
            $container->get(\Psr\Log\LoggerInterface::class),
            $container->get(SecurityAuditLogger::class),
            $container->get(AdminMutationGuard::class),
            $container->getStringParameter('base_path'),
            $container->getStringParameter('cookie_name'),
            $container->getBoolParameter('force_admin_https')
                || str_starts_with(strtolower($container->getStringParameter('base_url')), 'https://'),
        ));
        $container->set(WebAuthnAdminConfigExtender::class, static fn(Container $container): WebAuthnAdminConfigExtender => new WebAuthnAdminConfigExtender(
            $container->get(PermissionChecker::class),
            $container->get(WebAuthnCredentialRepository::class),
            $container->get(RecoveryCodeRepository::class),
            $container->get(WebAuthnAdminController::class),
            $container->get(TemplateRenderer::class),
        ), [AdminConfigExtenderInterface::class]);
        $container->set(ReauthenticationAdminConfigExtender::class, static fn(Container $container): ReauthenticationAdminConfigExtender => new ReauthenticationAdminConfigExtender(
            $container->get(AuthManager::class),
            $container->get(RequestStack::class),
            $container->get(Translator::class),
        ), [AdminConfigExtenderInterface::class]);

        // Request handlers
        $container->set(AdminMutationGuard::class, new AdminMutationGuard());
        $container->set(SameOriginRequestGuard::class, new SameOriginRequestGuard());
        $container->set(AdminThemeStylesheet::class, static fn(Container $container): AdminThemeStylesheet => new AdminThemeStylesheet(
            $container->get(DynamicConfigProvider::class),
        ));
        $container->set(AdminRequestHandler::class, fn(Container $container): \Register\Core\Admin\AdminRequestHandler => new AdminRequestHandler(
            $container->get(RequestStack::class),
            $container->get(AuthManager::class),
            $container->get(AdminThemeStylesheet::class),
            $container->get(WebAuthnAdminController::class),
            $container->get(SameOriginRequestGuard::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container,
            $container->get(ContentChangeDispatcher::class),
        ));

        $container->set(AdminAjaxRequestHandler::class, fn(Container $container): \Register\Core\Admin\AdminAjaxRequestHandler => new AdminAjaxRequestHandler(
            $container->get(RequestStack::class),
            $container->get(AuthManager::class),
            $container->get(PermissionChecker::class),
            $container->get(SameOriginRequestGuard::class),
            $container->get(AdminMutationGuard::class),
            $container->get(Translator::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container,
        ));

        // Structure page
        $container->set(ArticleManager::class, function (Container $container): \Register\Core\Model\ArticleManager {
            $provider = $container->get(DynamicConfigProvider::class);
            return new ArticleManager(
                $container->get(DbLayer::class),
                $container->get(\Register\Comment\CommentRepository::class),
                $container->get(\Register\Content\TagRepository::class),
                $container->get(SettingStorageInterface::class),
                $container->get(PermissionChecker::class),
                $provider->getBoolProxy('REGISTER_ADMIN_NEW_POS'),
                $provider->getBoolProxy('REGISTER_USE_HIERARCHY'),
                $container->get(ContentSlugService::class),
                $container->get(ContentChangeDispatcher::class),
                ...$container->getByTag(\Register\Content\ContentDeletionGuardInterface::class),
            );
        });

        $container->set(SiteStructureExtender::class, fn(Container $container): \Register\Core\Admin\SiteStructureExtender => new SiteStructureExtender(
            $container->get(TemplateRenderer::class),
        ), [AdminConfigExtenderInterface::class]);

        // Extensions
        $container->set(ExtensionManager::class, fn(Container $container): \Register\Core\Extensions\ExtensionManager => new ExtensionManager(
            $container->get(DbLayer::class),
            $container->get(ExtensionCache::class),
            $container->get(DynamicConfigProvider::class),
            $container->get(Translator::class),
            $container,
            $container->getStringParameter('root_dir'),
            new BaseModuleRegistry(),
        ));

        $container->set(ExtensionManagerAdapter::class, fn(Container $container): \Register\Core\Extensions\ExtensionManagerAdapter => new ExtensionManagerAdapter(
            $container->get(ExtensionManager::class),
            $container->get(PermissionChecker::class),
            $container->get(Translator::class),
            $container->get(SettingStorageInterface::class),
            $container->get(TemplateRenderer::class),
            $container->get(SecurityAuditLogger::class),
        ), [AdminConfigExtenderInterface::class]);

        // Dashboard providers
        $container->set(DashboardConfigExtender::class, fn(Container $container): \Register\Core\Admin\Dashboard\DashboardConfigExtender => new DashboardConfigExtender(
            $container->getByTag(DashboardStatProviderInterface::class),
            $container->getByTag(DashboardBlockProviderInterface::class),
            $container->getByTag(SystemStatusProviderInterface::class),
            $container->get(PermissionChecker::class),
            $container->get(TemplateRenderer::class),
        ), [AdminConfigExtenderInterface::class]);
        $container->set(DashboardEnvironmentProvider::class, fn(Container $container): \Register\Core\Admin\Dashboard\DashboardEnvironmentProvider => new DashboardEnvironmentProvider(
            $container->get(Translator::class),
            $container->get(TemplateRenderer::class),
            $container->get(DashboardDatabaseProvider::class),
        ), [SystemStatusProviderInterface::class]);
        $container->set(DashboardPerformanceProvider::class, fn(Container $container): DashboardPerformanceProvider => new DashboardPerformanceProvider(
            $container->get(TemplateRenderer::class),
            $container->get(RequestPerformanceInspector::class),
        ), [SystemStatusProviderInterface::class]);
        $container->set(DashboardCompressionProvider::class, fn(Container $container): DashboardCompressionProvider => new DashboardCompressionProvider(
            $container->get(TemplateRenderer::class),
            $container->getStringParameter('public_root_dir') . '_cache/',
            !$container->getBoolParameter('disable_cache'),
        ), [SystemStatusProviderInterface::class]);
        $container->set(QueryProfilerToken::class, fn(Container $container): QueryProfilerToken => new QueryProfilerToken(
            $container->get(SettingStorageInterface::class),
        ));
        $container->set(QueryProfilerController::class, fn(Container $container): QueryProfilerController => new QueryProfilerController(
            $container->get(QueryProfilerState::class),
            $container->get(QueryProfilerLog::class),
            $container->get(RequestQueryProfiler::class),
            $container->get(QueryProfilerToken::class),
            $container->get(PermissionChecker::class),
            $container->get(AdminMutationGuard::class),
            $container->get(\Register\Core\Model\UrlBuilder::class),
            $container->get(Translator::class),
            $container->get(\Psr\Log\LoggerInterface::class),
        ));
        $container->set(DashboardQueryProfilerProvider::class, fn(Container $container): DashboardQueryProfilerProvider => new DashboardQueryProfilerProvider(
            $container->get(TemplateRenderer::class),
            $container->get(QueryProfilerState::class),
            $container->get(QueryProfilerInspector::class),
            $container->get(QueryProfilerToken::class),
            $container->get(PermissionChecker::class),
        ), [SystemStatusProviderInterface::class]);
        $container->set(SecurityAlertDetector::class, static fn(Container $container): SecurityAlertDetector => new SecurityAlertDetector(
            $container->getStringParameter('log_dir') . 'security-events.jsonl',
            $container->getStringParameter('log_dir') . 'csp-violations.jsonl',
        ));
        $container->set(DashboardSecurityProvider::class, static fn(Container $container): DashboardSecurityProvider => new DashboardSecurityProvider(
            $container->get(TemplateRenderer::class),
            $container->get(SecurityAlertDetector::class),
        ), [DashboardStatProviderInterface::class, SystemStatusProviderInterface::class]);

        $container->set(BackupToken::class, fn(Container $container): BackupToken => new BackupToken(
            $container->get(SettingStorageInterface::class),
        ));
        $container->set(BackupAdminController::class, fn(Container $container): BackupAdminController => new BackupAdminController(
            $container->get(BackupManager::class),
            $container->get(BackupToken::class),
            $container->get(PermissionChecker::class),
            $container->get(AuthManager::class),
            $container->get(Translator::class),
            $container->get(\Psr\Log\LoggerInterface::class),
            $container->get(SecurityAuditLogger::class),
            $container->get(AdminMutationGuard::class),
        ));
        $container->set(DashboardBackupProvider::class, fn(Container $container): DashboardBackupProvider => new DashboardBackupProvider(
            $container->get(TemplateRenderer::class),
            $container->get(BackupManager::class),
            $container->get(BackupScheduler::class),
            $container->get(BackupToken::class),
            $container->get(PermissionChecker::class),
        ), [SystemStatusProviderInterface::class]);

        $container->set(ArchiveCapabilities::class, new ArchiveCapabilities());
        $container->set(ReleaseArchiveExtractor::class, fn(Container $container): ReleaseArchiveExtractor => new ReleaseArchiveExtractor(
            $container->get(ArchiveCapabilities::class),
        ));
        $container->set(UpdateStorage::class, fn(Container $container): UpdateStorage => new UpdateStorage(
            UpdateDirectoryResolver::resolve($container->getStringParameter('root_dir')),
        ));
        $container->set(UpdatePlanner::class, fn(Container $container): UpdatePlanner => new UpdatePlanner(
            $container->getStringParameter('root_dir'),
            $container->getStringParameter('public_root_dir'),
        ));
        $container->set(UpdateApplier::class, fn(Container $container): UpdateApplier => new UpdateApplier(
            $container->getStringParameter('root_dir'),
            $container->getStringParameter('public_root_dir'),
        ));
        $container->set(MaintenanceMode::class, fn(Container $container): MaintenanceMode => new MaintenanceMode(
            $container->getStringParameter('root_dir'),
        ));
        $container->set(GeneratedAssetCacheCleaner::class, fn(Container $container): GeneratedAssetCacheCleaner => new GeneratedAssetCacheCleaner(
            $container->getStringParameter('public_root_dir'),
        ));
        $container->set(UpdateManager::class, fn(Container $container): UpdateManager => new UpdateManager(
            $container->get(UpdateStorage::class),
            $container->get(ReleaseArchiveExtractor::class),
            $container->get(UpdatePlanner::class),
            $container->get(UpdateApplier::class),
            $container->get(BackupManager::class),
            $container->get(\Register\Schema\SchemaManager::class),
            $container->get(ExtensionCache::class),
            $container->get(DynamicConfigProvider::class),
            $container->get(GeneratedAssetCacheCleaner::class),
            $container->get(MaintenanceMode::class),
            $container->get(\Psr\Log\LoggerInterface::class),
            $container->getStringParameter('root_dir'),
        ));
        $container->set(UpdateToken::class, fn(Container $container): UpdateToken => new UpdateToken(
            $container->get(SettingStorageInterface::class),
        ));
        $container->set(UpdateAdminController::class, fn(Container $container): UpdateAdminController => new UpdateAdminController(
            $container->get(UpdateManager::class),
            $container->get(UpdateToken::class),
            $container->get(PermissionChecker::class),
            $container->get(AuthManager::class),
            $container->get(AdminMutationGuard::class),
            $container->get(Translator::class),
            $container->get(\Psr\Log\LoggerInterface::class),
        ));
        $container->set(UpdateAdminConfigExtender::class, fn(Container $container): UpdateAdminConfigExtender => new UpdateAdminConfigExtender(
            $container->get(PermissionChecker::class),
            $container->get(TemplateRenderer::class),
            $container->get(UpdateManager::class),
            $container->get(UpdateToken::class),
            $container->get(ArchiveCapabilities::class),
            $container->getStringParameter('root_dir'),
            $container->getStringParameter('public_root_dir'),
        ), [AdminConfigExtenderInterface::class]);

        $container->set(DashboardDatabaseProvider::class, fn(Container $container): \Register\Core\Admin\Dashboard\DashboardDatabaseProvider => new DashboardDatabaseProvider(
            $container->get(DbLayer::class),
            $container->getStringParameter('db_type'),
            $container->getStringParameter('db_name'),
            $container->getStringParameter('db_prefix'),
        ));

        $container->set(DashboardContentProvider::class, fn(Container $container): DashboardContentProvider => new DashboardContentProvider(
            $container->get(TemplateRenderer::class),
            $container->get(ContentStatisticsRepository::class),
            $container->get(CommentRepository::class),
            $container->getStringParameter('root_dir') . '_admin/templates/dashboard/publication-item.php.inc',
        ), [DashboardStatProviderInterface::class]);

        $container->set(PathToAdminEntityConverter::class, fn(Container $container): \Register\Core\Admin\PathToAdminEntityConverter => new PathToAdminEntityConverter(
            $container->get(ArticleProvider::class),
        ));

        $container->set(PictureFileNameHelper::class, fn(Container $container): \Register\Core\Admin\Picture\PictureFileNameHelper => new PictureFileNameHelper(
            $container->get(Translator::class),
            $container->getStringParameter('allowed_extensions'),
        ));

        $container->set(PictureStorageQuota::class, fn(Container $container): PictureStorageQuota => new PictureStorageQuota(
            $container->get(Translator::class),
            $container->getStringParameter('image_dir'),
            $container->getStringParameter('cache_dir') . 'picture-upload-quota.lock',
            $container->getIntParameter('upload_quota_bytes'),
        ));

        $container->set(PictureReserveManager::class, fn(Container $container): \Register\Core\Admin\Picture\PictureReserveManager => new PictureReserveManager(
            $container->get(PictureFileNameHelper::class),
            $container->getStringParameter('image_dir'),
            $container->getStringParameter('cache_dir'),
        ));

        $container->set(PictureManager::class, function (Container $container): \Register\Core\Admin\Picture\PictureManager {
            $templateRenderer = $container->get(TemplateRenderer::class);
            if (!$templateRenderer instanceof CustomTemplateRenderer) {
                throw new \LogicException('The picture manager requires the CMS template renderer.');
            }

            return new PictureManager(
                $container->get(Translator::class),
                $templateRenderer,
                $container->get(SettingStorageInterface::class),
                $container->get(PictureFileNameHelper::class),
                $container->get(PictureStorageQuota::class),
                $container->getStringParameter('base_path'),
                $container->getStringParameter('image_dir'),
            );
        });
        $container->set(MediaConfigExtender::class, fn(Container $container): MediaConfigExtender => new MediaConfigExtender(
            $container->get(PermissionChecker::class),
            $container->get(TemplateRenderer::class),
            $container->getStringParameter('image_path'),
        ), [AdminConfigExtenderInterface::class]);
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(CustomTemplateRendererEvent::class, static function (CustomTemplateRendererEvent $event): void {
            $event->extraScripts[] = $event->basePath . '/_admin/js/autocomplete.js';
            $event->extraScripts[] = $event->basePath . '/_admin/js/update.js';
            $event->extraStyles[]  = $event->basePath . '/_admin/css/update.css';
        });

        $eventDispatcher->addListener(AdminAjaxControllerMapEvent::class, static function (AdminAjaxControllerMapEvent $event) use ($container): void {
            $event->allowGet('register_tag_suggestions');
            $event->controllerMap['register_tag_suggestions'] = static function (PermissionChecker $permissionChecker) use ($container): \Symfony\Component\HttpFoundation\JsonResponse {
                if (!$permissionChecker->isGranted(PermissionChecker::PERMISSION_CREATE_ARTICLES)) {
                    return new \Symfony\Component\HttpFoundation\JsonResponse(
                        ['success' => false, 'message' => 'No permission'],
                        \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN,
                    );
                }

                return new \Symfony\Component\HttpFoundation\JsonResponse([
                    'success' => true,
                    'tags'    => array_values($container->get(TagsProvider::class)->getAllTags()),
                ]);
            };
            $event->controllerMap['register_ai_generate'] = static fn(PermissionChecker $permissionChecker, Request $request): \Symfony\Component\HttpFoundation\JsonResponse => $container
                ->get(AiEditorController::class)
                ->generate($permissionChecker, $request);
            $event->controllerMap['register_ai_generate_alt'] = static fn(PermissionChecker $permissionChecker, Request $request): \Symfony\Component\HttpFoundation\JsonResponse => $container
                ->get(AiEditorController::class)
                ->generateAlt($permissionChecker, $request);
            $event->controllerMap['register_ai_check'] = static fn(PermissionChecker $permissionChecker, Request $request): \Symfony\Component\HttpFoundation\JsonResponse => $container
                ->get(AiEditorController::class)
                ->checkAvailability($permissionChecker, $request);
            $event->controllerMap['register_backup_create'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(BackupAdminController::class)
                ->create($request);
            $event->controllerMap['register_backup_download'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(BackupAdminController::class)
                ->downloadLatest($request);
            $event->controllerMap['register_query_profiler'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(QueryProfilerController::class)
                ->mutate($request);
            $event->controllerMap['register_update_start'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(UpdateAdminController::class)
                ->start($request);
            $event->controllerMap['register_update_chunk'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(UpdateAdminController::class)
                ->chunk($request);
            $event->controllerMap['register_update_prepare'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(UpdateAdminController::class)
                ->prepare($request);
            $event->controllerMap['register_update_apply'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(UpdateAdminController::class)
                ->apply($request);
            $event->controllerMap['register_update_finish'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(UpdateAdminController::class)
                ->finish($request);
            $event->controllerMap['register_update_status'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(UpdateAdminController::class)
                ->status($request);
            $event->controllerMap['register_saved_list_view_save'] = static fn(PermissionChecker $permissionChecker, Request $request): \Symfony\Component\HttpFoundation\JsonResponse => $container
                ->get(SavedListViewController::class)
                ->save($permissionChecker, $request);
            $event->controllerMap['register_saved_list_view_delete'] = static fn(PermissionChecker $permissionChecker, Request $request): \Symfony\Component\HttpFoundation\JsonResponse => $container
                ->get(SavedListViewController::class)
                ->delete($permissionChecker, $request);
            $event->controllerMap['register_bulk_list_action'] = static fn(PermissionChecker $permissionChecker, Request $request): \Symfony\Component\HttpFoundation\JsonResponse => $container
                ->get(BulkListActionController::class)
                ->execute($permissionChecker, $request);
        });

        $eventDispatcher->addListener(CustomMenuGeneratorEvent::class, function (CustomMenuGeneratorEvent $event) use ($container): void {
            $size = $container->get(CommentRepository::class)->countPending();

            if ($size > 0) {
                $event->addSignal('Comment', new Signal((string)$size, 'New comments', '?entity=Comment&action=list&status=0&apply_filter=0'));
            }

            $extensionManager = $container->get(ExtensionManager::class);
            $n                = $extensionManager->getUpgradableExtensionNum();
            if ($n > 0) {
                $event->addSignal('Extension', new Signal((string)$n, 'Extensions for upgrade', '?entity=Extension'));
            }

            $authManager            = $container->get(AuthManager::class);
            $totalUserSessionsCount = $authManager->getTotalUserSessionsCount();
            if ($totalUserSessionsCount > 1) {
                $event->addSignal('Session', new Signal((string)$totalUserSessionsCount, 'Other sessions', '?entity=Session&action=list'));
            }
        });

        $eventDispatcher->addListener(RedirectFromPublicEvent::class, function (RedirectFromPublicEvent $event) use ($container): void {
            $converter   = $container->get(PathToAdminEntityConverter::class);
            $queryParams = $converter->getQueryParams($event->path);
            if ($queryParams !== null) {
                foreach ($queryParams as $key => $param) {
                    $event->request->query->set((string)$key, $param);
                }

                $event->stopPropagation();
            }
        });
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
    }
}
