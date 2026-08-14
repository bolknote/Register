<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Translator;
use S2\Cms\Admin\AdminConfigExtenderInterface;
use S2\Cms\Admin\Dashboard\DashboardStatProviderInterface;
use S2\Cms\Admin\DynamicConfigFormExtenderInterface;
use S2\Cms\Admin\Event\RedirectFromPublicEvent;
use S2\Cms\Admin\TranslationProviderInterface;
use S2\Cms\AdminYard\CustomTemplateRendererEvent;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Model\TagsProvider;
use S2\Cms\Pdo\DbLayer;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlGenerator;
use Register\Content\Admin\DashboardContentProvider;
use Register\Content\Admin\ContentRevisionService;
use Register\Module\Blog\Admin\AdminConfigExtender;
use Register\Module\Blog\Admin\DynamicConfigFormExtender;
use Register\Module\Blog\Admin\PathToAdminEntityConverter;
use Register\Module\Blog\Admin\TranslationProvider;
use Register\Content\ContentType;
use Register\Content\ContentStatisticsRepository;
use Register\Module\Blog\Model\PostProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class AdminModule implements ContainerModuleInterface, ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(AdminConfigExtender::class, fn(Container $container): \Register\Module\Blog\Admin\AdminConfigExtender => new AdminConfigExtender(
            $container->get(PermissionChecker::class),
            $container->get(Translator::class),
            $container->get(TagsProvider::class),
            $container->get(\Register\Content\TagRepository::class),
            $container->get(PostProvider::class),
            $container->get(ContentUrlGenerator::class),
            $container->get(ContentRevisionService::class),
            $container->get(ContentSlugService::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->getStringParameter('db_type'),
            $container->getStringParameter('db_prefix'),
        ), [AdminConfigExtenderInterface::class]);

        $container->set(DynamicConfigFormExtender::class, new DynamicConfigFormExtender(), [DynamicConfigFormExtenderInterface::class]);

        $container->set(TranslationProvider::class, new TranslationProvider(), [TranslationProviderInterface::class]);

        $container->set(DashboardContentProvider::POST_SERVICE_ID, fn(Container $container): DashboardContentProvider => new DashboardContentProvider(
            $container->get(TemplateRenderer::class),
            $container->get(ContentStatisticsRepository::class),
            ContentType::POST,
            __DIR__ . '/resources/views/dashboard/blog-item.php.inc',
            'posts_num',
        ), [DashboardStatProviderInterface::class]);

        $container->set(PathToAdminEntityConverter::class, fn(Container $container): \Register\Module\Blog\Admin\PathToAdminEntityConverter => new PathToAdminEntityConverter(
            $container->get(DbLayer::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(CustomTemplateRendererEvent::class, function (CustomTemplateRendererEvent $event) : void {
            $event->extraStyles[] = $event->basePath . '/_assets/register/blog/admin.css';
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

}
