<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin;

use Register\Ai\Admin\AiEditorController;
use Register\Ai\AiClient;
use Register\Ai\AiSettings;
use Register\Backup\Admin\BackupAdminController;
use Register\Backup\Admin\BackupToken;
use Register\Backup\Admin\DashboardBackupProvider;
use Register\Backup\BackupManager;
use Register\Backup\BackupScheduler;
use Register\Content\Admin\DashboardContentProvider;
use Register\Content\Admin\ContentRevisionService;
use Register\Content\ContentStatisticsRepository;
use Register\Content\ContentChangeDispatcher;
use Register\Comment\CommentRepository;
use Register\Module\BaseModuleRegistry;
use Register\Url\ContentSlugService;
use S2\AdminYard\Database\PdoDataProvider;
use S2\AdminYard\Database\TypeTransformer;
use S2\AdminYard\Form\FormControlFactoryInterface;
use S2\AdminYard\Form\FormFactory;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Translator;
use S2\Cms\Admin\Dashboard\DashboardBlockProviderInterface;
use S2\Cms\Admin\Dashboard\DashboardConfigExtender;
use S2\Cms\Admin\Dashboard\DashboardDatabaseProvider;
use S2\Cms\Admin\Dashboard\DashboardEnvironmentProvider;
use S2\Cms\Admin\Dashboard\DashboardStatProviderInterface;
use S2\Cms\Admin\Dashboard\SystemStatusProviderInterface;
use S2\Cms\Admin\Controller\CommentControllerFactory;
use S2\Cms\Admin\Event\RedirectFromPublicEvent;
use S2\Cms\Admin\Event\AdminAjaxControllerMapEvent;
use S2\Cms\Admin\Picture\PictureFileNameHelper;
use S2\Cms\Admin\Picture\PictureManager;
use S2\Cms\Admin\Picture\PictureReserveManager;
use S2\Cms\AdminYard\CustomMenuGeneratorEvent;
use S2\Cms\AdminYard\CustomTemplateRenderer;
use S2\Cms\AdminYard\Form\CustomFormControlFactory;
use S2\Cms\AdminYard\Signal;
use S2\Cms\AdminYard\UserSettingStorage;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Comment\Antispam\SpamFeedbackService;
use S2\Cms\Extensions\ExtensionManager;
use S2\Cms\Extensions\ExtensionManagerAdapter;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ExtensionInterface;
use S2\Cms\Framework\StatefulServiceInterface;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\Model\ArticleManager;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\AuthManager;
use Register\Comment\ContentCommentNotifier;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Model\TagsProvider;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;

class AdminExtension implements ExtensionInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(FormControlFactoryInterface::class, fn(Container $container): \S2\Cms\AdminYard\Form\CustomFormControlFactory => new CustomFormControlFactory(
            $container->get(Translator::class)
        ));

        // AdminYard services
        $container->set(TypeTransformer::class, fn(Container $_container): \S2\AdminYard\Database\TypeTransformer => new TypeTransformer());

        $container->set(PdoDataProvider::class, fn(Container $container): \S2\AdminYard\Database\PdoDataProvider => new PdoDataProvider(
            $container->get(\PDO::class),
            $container->get(TypeTransformer::class),
        ));

        $container->set(TranslationProvider::class, fn(Container $container): \S2\Cms\Admin\TranslationProvider => new TranslationProvider($container->getStringParameter('root_dir')), [TranslationProviderInterface::class]);

        $container->set(Translator::class, function (Container $container): \S2\AdminYard\Translator {
            $provider = $container->get(DynamicConfigProvider::class);
            $language = $provider->getStringProxy('S2_LANGUAGE')->get();

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

        $container->set(FormFactory::class, fn(Container $container): \S2\AdminYard\Form\FormFactory => new FormFactory(
            $container->get(FormControlFactoryInterface::class),
            $container->get(Translator::class),
            $container->get(PdoDataProvider::class),
        ));

        $container->set(TemplateRenderer::class, fn(Container $container): \S2\Cms\AdminYard\CustomTemplateRenderer => new CustomTemplateRenderer(
            $container->get(Translator::class),
            $container->get(DynamicConfigProvider::class),
            $container->get(PermissionChecker::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->getStringParameter('base_path'),
            $container->getStringParameter('root_dir'),
        ), [StatefulServiceInterface::class]);

        $container->set(SettingStorageInterface::class, fn(Container $container): \S2\Cms\AdminYard\UserSettingStorage => new UserSettingStorage(
            $container->get(PermissionChecker::class),
            $container->get(DbLayer::class),
        ), [StatefulServiceInterface::class]);

        $container->set(ResourceProvider::class, fn(Container $container): \S2\Cms\Admin\ResourceProvider => new ResourceProvider(
            $container->getStringParameter('root_dir'),
        ));

        $container->set(DynamicConfigFormBuilder::class, fn(Container $container): \S2\Cms\Admin\DynamicConfigFormBuilder => new DynamicConfigFormBuilder(
            $container->get(PermissionChecker::class),
            $container->get(Translator::class),
            $container->get(TypeTransformer::class),
            $container->get(FormFactory::class),
            $container->get(TemplateRenderer::class),
            $container->get(ResourceProvider::class),
            $container->get(RequestStack::class),
            $container->get(SettingStorageInterface::class),
            $container->get(DynamicConfigProvider::class),
            ...$container->getByTag(DynamicConfigFormExtenderInterface::class),
        ));

        $container->set(AdminConfigProvider::class, function (Container $container): \S2\Cms\Admin\AdminConfigProvider {
            $dbType   = $container->getStringParameter('db_type');
            $dbPrefix = $container->getStringParameter('db_prefix');
            $provider = $container->get(DynamicConfigProvider::class);
            return new AdminConfigProvider(
                $container->get(PermissionChecker::class),
                $container->get(AuthManager::class),
                $container->get(DynamicConfigFormBuilder::class),
                $provider,
                $provider->getBoolProxy('S2_ADMIN_CUT'),
                $container->get(Translator::class),
                $container->get(ArticleProvider::class),
                $container->get(ContentRevisionService::class),
                $container->get(ContentSlugService::class),
                $container->get(\Register\Url\ContentUrlGenerator::class),
                $container->get(TagsProvider::class),
                $container->get(\Register\Content\TagRepository::class),
                $container->get(ContentCommentNotifier::class),
                $container->get(ExtensionCache::class),
                $container->get(ContentChangeDispatcher::class),
                $container->get(\Register\Content\ContentPublicationScheduler::class),
                $container->get(CommentControllerFactory::class),
                $container->get(\S2\Cms\Comment\Antispam\SpamMetricsRepository::class),
                $container->get(RequestStack::class),
                $dbType,
                $dbPrefix,
                ...$container->getByTag(AdminConfigExtenderInterface::class)
            );
        }, [StatefulServiceInterface::class]);

        $container->set(AiSettings::class, fn(Container $container): AiSettings => new AiSettings(
            $container->get(DynamicConfigProvider::class),
        ));
        $container->set(AiClient::class, fn(Container $container): AiClient => new AiClient(
            $container->get(HttpClient::class),
            $container->get(AiSettings::class),
        ));
        $container->set(AiEditorController::class, fn(Container $container): AiEditorController => new AiEditorController(
            $container->get(AiClient::class),
            $container->get(AiSettings::class),
            $container->get(AdminConfigProvider::class),
            $container->get(SettingStorageInterface::class),
            $container->get(Translator::class),
        ));

        $container->set(CommentControllerFactory::class, fn(Container $container): \S2\Cms\Admin\Controller\CommentControllerFactory => new CommentControllerFactory(
            $container->get(SpamFeedbackService::class),
        ));

        $container->set(AdminPanelFactory::class, fn(Container $container): \S2\Cms\Admin\AdminPanelFactory => new AdminPanelFactory($container));

        $container->set(PermissionChecker::class, fn(Container $_container): \S2\Cms\Model\PermissionChecker => new PermissionChecker(), [StatefulServiceInterface::class]);

        $container->set(AuthManager::class, fn(Container $container): \S2\Cms\Model\AuthManager => new AuthManager(
            $container->get(DbLayer::class),
            $container->get(PermissionChecker::class),
            $container->get(RequestStack::class),
            $container->get(TemplateRenderer::class),
            $container->get(Translator::class),
            $container->get(\S2\Cms\Model\LoginRateLimiter::class),
            $container->getStringParameter('base_path'),
            $container->getStringParameter('base_url'),
            $container->getStringParameter('cookie_name'),
            $container->getBoolParameter('force_admin_https'),
        ));

        // Request handlers
        $container->set(AdminRequestHandler::class, fn(Container $container): \S2\Cms\Admin\AdminRequestHandler => new AdminRequestHandler(
            $container->get(RequestStack::class),
            $container->get(AuthManager::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container,
            $container->get(ContentChangeDispatcher::class),
        ));

        $container->set(AdminAjaxRequestHandler::class, fn(Container $container): \S2\Cms\Admin\AdminAjaxRequestHandler => new AdminAjaxRequestHandler(
            $container->get(RequestStack::class),
            $container->get(AuthManager::class),
            $container->get(PermissionChecker::class),
            $container->get(Translator::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container,
        ));

        // Structure page
        $container->set(ArticleManager::class, function (Container $container): \S2\Cms\Model\ArticleManager {
            $provider = $container->get(DynamicConfigProvider::class);
            return new ArticleManager(
                $container->get(DbLayer::class),
                $container->get(\Register\Comment\CommentRepository::class),
                $container->get(\Register\Content\TagRepository::class),
                $container->get(SettingStorageInterface::class),
                $container->get(PermissionChecker::class),
                $provider->getBoolProxy('S2_ADMIN_NEW_POS'),
                $provider->getBoolProxy('S2_USE_HIERARCHY'),
                $container->get(ContentSlugService::class),
                $container->get(ContentChangeDispatcher::class),
                ...$container->getByTag(\Register\Content\ContentDeletionGuardInterface::class),
            );
        });

        $container->set(SiteStructureExtender::class, fn(Container $container): \S2\Cms\Admin\SiteStructureExtender => new SiteStructureExtender(
            $container->get(TemplateRenderer::class),
        ), [AdminConfigExtenderInterface::class]);

        // Extensions
        $container->set(ExtensionManager::class, fn(Container $container): \S2\Cms\Extensions\ExtensionManager => new ExtensionManager(
            $container->get(DbLayer::class),
            $container->get(ExtensionCache::class),
            $container->get(DynamicConfigProvider::class),
            $container->get(Translator::class),
            $container,
            $container->getStringParameter('root_dir'),
            new BaseModuleRegistry(),
        ));

        $container->set(ExtensionManagerAdapter::class, fn(Container $container): \S2\Cms\Extensions\ExtensionManagerAdapter => new ExtensionManagerAdapter(
            $container->get(ExtensionManager::class),
            $container->get(PermissionChecker::class),
            $container->get(Translator::class),
            $container->get(SettingStorageInterface::class),
            $container->get(TemplateRenderer::class),
        ), [AdminConfigExtenderInterface::class]);

        // Dashboard providers
        $container->set(DashboardConfigExtender::class, fn(Container $container): \S2\Cms\Admin\Dashboard\DashboardConfigExtender => new DashboardConfigExtender(
            $container->getByTag(DashboardStatProviderInterface::class),
            $container->getByTag(DashboardBlockProviderInterface::class),
            $container->getByTag(SystemStatusProviderInterface::class),
            $container->get(PermissionChecker::class),
            $container->get(TemplateRenderer::class),
        ), [AdminConfigExtenderInterface::class]);
        $container->set(DashboardEnvironmentProvider::class, fn(Container $container): \S2\Cms\Admin\Dashboard\DashboardEnvironmentProvider => new DashboardEnvironmentProvider(
            $container->get(Translator::class),
            $container->get(TemplateRenderer::class),
            $container->get(DashboardDatabaseProvider::class),
        ), [SystemStatusProviderInterface::class]);

        $container->set(BackupToken::class, fn(Container $container): BackupToken => new BackupToken(
            $container->get(SettingStorageInterface::class),
        ));
        $container->set(BackupAdminController::class, fn(Container $container): BackupAdminController => new BackupAdminController(
            $container->get(BackupManager::class),
            $container->get(BackupToken::class),
            $container->get(PermissionChecker::class),
            $container->get(Translator::class),
            $container->get(\Psr\Log\LoggerInterface::class),
        ));
        $container->set(DashboardBackupProvider::class, fn(Container $container): DashboardBackupProvider => new DashboardBackupProvider(
            $container->get(TemplateRenderer::class),
            $container->get(BackupManager::class),
            $container->get(BackupScheduler::class),
            $container->get(BackupToken::class),
            $container->get(PermissionChecker::class),
        ), [SystemStatusProviderInterface::class]);

        $container->set(DashboardDatabaseProvider::class, fn(Container $container): \S2\Cms\Admin\Dashboard\DashboardDatabaseProvider => new DashboardDatabaseProvider(
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

        $container->set(PathToAdminEntityConverter::class, fn(Container $container): \S2\Cms\Admin\PathToAdminEntityConverter => new PathToAdminEntityConverter(
            $container->get(ArticleProvider::class),
        ));

        $container->set(PictureFileNameHelper::class, fn(Container $container): \S2\Cms\Admin\Picture\PictureFileNameHelper => new PictureFileNameHelper(
            $container->get(Translator::class),
            $container->getStringParameter('allowed_extensions'),
        ));

        $container->set(PictureReserveManager::class, fn(Container $container): \S2\Cms\Admin\Picture\PictureReserveManager => new PictureReserveManager(
            $container->get(PictureFileNameHelper::class),
            $container->getStringParameter('image_dir'),
            $container->getStringParameter('cache_dir'),
        ));

        $container->set(PictureManager::class, function (Container $container): \S2\Cms\Admin\Picture\PictureManager {
            $templateRenderer = $container->get(TemplateRenderer::class);
            if (!$templateRenderer instanceof CustomTemplateRenderer) {
                throw new \LogicException('The picture manager requires the CMS template renderer.');
            }

            return new PictureManager(
                $container->get(Translator::class),
                $templateRenderer,
                $container->get(SettingStorageInterface::class),
                $container->get(PictureFileNameHelper::class),
                $container->getStringParameter('base_path'),
                $container->getStringParameter('image_dir'),
            );
        });
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(AdminAjaxControllerMapEvent::class, static function (AdminAjaxControllerMapEvent $event) use ($container): void {
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
            $event->controllerMap['register_backup_create'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(BackupAdminController::class)
                ->create($request);
            $event->controllerMap['register_backup_download'] = static fn(PermissionChecker $_permissionChecker, Request $request): \Symfony\Component\HttpFoundation\Response => $container
                ->get(BackupAdminController::class)
                ->downloadLatest($request);
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
