<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin;

use Register\Content\Admin\DashboardContentProvider;
use Register\Content\ContentStatisticsRepository;
use Register\Content\ContentType;
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
use S2\Cms\Admin\Controller\CommentControllerFactory;
use S2\Cms\Admin\Event\RedirectFromPublicEvent;
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
use S2\Cms\Model\ArticleManager;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\AuthManager;
use Register\Comment\ContentCommentNotifier;
use S2\Cms\Model\CommentProvider;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Model\TagsProvider;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
                $container->get(ContentSlugService::class),
                $container->get(\Register\Url\ContentUrlGenerator::class),
                $container->get(TagsProvider::class),
                $container->get(\Register\Content\TagRepository::class),
                $container->get(ContentCommentNotifier::class),
                $container->get(ExtensionCache::class),
                $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
                $container->get(CommentControllerFactory::class),
                $container->get(\S2\Cms\Comment\Antispam\SpamMetricsRepository::class),
                $dbType,
                $dbPrefix,
                ...$container->getByTag(AdminConfigExtenderInterface::class)
            );
        }, [StatefulServiceInterface::class]);

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
            $container->get(PermissionChecker::class),
            $container->get(TemplateRenderer::class),
            $container->getStringParameter('version'),
        ), [AdminConfigExtenderInterface::class]);
        $container->set(DashboardEnvironmentProvider::class, fn(Container $container): \S2\Cms\Admin\Dashboard\DashboardEnvironmentProvider => new DashboardEnvironmentProvider(
            $container->get(Translator::class),
            $container->get(TemplateRenderer::class),
        ), [DashboardStatProviderInterface::class]);

        $container->set(DashboardDatabaseProvider::class, fn(Container $container): \S2\Cms\Admin\Dashboard\DashboardDatabaseProvider => new DashboardDatabaseProvider(
            $container->get(TemplateRenderer::class),
            $container->get(DbLayer::class),
            $container->getStringParameter('db_type'),
            $container->getStringParameter('db_name'),
            $container->getStringParameter('db_prefix'),
        ), [DashboardStatProviderInterface::class]);

        $container->set(DashboardContentProvider::PAGE_SERVICE_ID, fn(Container $container): DashboardContentProvider => new DashboardContentProvider(
            $container->get(TemplateRenderer::class),
            $container->get(ContentStatisticsRepository::class),
            ContentType::PAGE,
            $container->getStringParameter('root_dir') . '_admin/templates/dashboard/article-item.php.inc',
            'articles_num',
        ), [DashboardStatProviderInterface::class]);

        $container->set(PathToAdminEntityConverter::class, fn(Container $container): \S2\Cms\Admin\PathToAdminEntityConverter => new PathToAdminEntityConverter(
            $container->get(ArticleProvider::class),
        ));

        $container->set(PictureFileNameHelper::class, fn(Container $container): \S2\Cms\Admin\Picture\PictureFileNameHelper => new PictureFileNameHelper(
            $container->get(Translator::class),
            $container->get(PermissionChecker::class),
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
        $eventDispatcher->addListener(CustomMenuGeneratorEvent::class, function (CustomMenuGeneratorEvent $event) use ($container): void {
            $commentProvider = $container->get(CommentProvider::class);
            $size            = $commentProvider->getPendingCommentsCount();

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
