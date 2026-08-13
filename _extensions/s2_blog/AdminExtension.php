<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   s2_search
 */

declare(strict_types = 1);

namespace s2_extensions\s2_blog;

use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Translator;
use S2\Cms\Admin\AdminConfigExtenderInterface;
use S2\Cms\Admin\Dashboard\DashboardStatProviderInterface;
use S2\Cms\Admin\DynamicConfigFormExtenderInterface;
use S2\Cms\Admin\Event\RedirectFromPublicEvent;
use S2\Cms\Admin\TranslationProviderInterface;
use S2\Cms\AdminYard\CustomMenuGeneratorEvent;
use S2\Cms\AdminYard\CustomTemplateRendererEvent;
use S2\Cms\AdminYard\Signal;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ExtensionInterface;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Model\TagsProvider;
use S2\Cms\Pdo\DbLayer;
use s2_extensions\s2_blog\Admin\AdminConfigExtender;
use s2_extensions\s2_blog\Admin\DashboardBlogProvider;
use s2_extensions\s2_blog\Admin\DynamicConfigFormExtender;
use s2_extensions\s2_blog\Admin\PathToAdminEntityConverter;
use s2_extensions\s2_blog\Admin\TranslationProvider;
use s2_extensions\s2_blog\Model\BlogCommentNotifier;
use s2_extensions\s2_blog\Model\BlogCommentProvider;
use s2_extensions\s2_blog\Model\PostProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouteCollection;

class AdminExtension implements ExtensionInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(AdminConfigExtender::class, fn(Container $container): \s2_extensions\s2_blog\Admin\AdminConfigExtender => new AdminConfigExtender(
            $container->get(PermissionChecker::class),
            $container->get(Translator::class),
            $container->get(TagsProvider::class),
            $container->get(PostProvider::class),
            $container->get(BlogUrlBuilder::class),
            $container->get(BlogCommentNotifier::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->getStringParameter('db_type'),
            $container->getStringParameter('db_prefix'),
        ), [AdminConfigExtenderInterface::class]);

        $container->set(DynamicConfigFormExtender::class, fn(Container $_container): \s2_extensions\s2_blog\Admin\DynamicConfigFormExtender => new DynamicConfigFormExtender(), [DynamicConfigFormExtenderInterface::class]);

        $container->set(TranslationProvider::class, fn(Container $_container): \s2_extensions\s2_blog\Admin\TranslationProvider => new TranslationProvider(), [TranslationProviderInterface::class]);

        $container->set(DashboardBlogProvider::class, fn(Container $container): \s2_extensions\s2_blog\Admin\DashboardBlogProvider => new DashboardBlogProvider(
            $container->get(TemplateRenderer::class),
            $container->get(DbLayer::class),
            $container->getStringParameter('root_dir')
        ), [DashboardStatProviderInterface::class]);

        $container->set(BlogCommentProvider::class, fn(Container $container): \s2_extensions\s2_blog\Model\BlogCommentProvider => new BlogCommentProvider($container->get(DbLayer::class)));

        $container->set(PathToAdminEntityConverter::class, fn(Container $container): \s2_extensions\s2_blog\Admin\PathToAdminEntityConverter => new PathToAdminEntityConverter(
            $container->get(DbLayer::class),
            $container->get(BlogUrlBuilder::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(CustomTemplateRendererEvent::class, function (CustomTemplateRendererEvent $event) : void {
            $event->extraStyles[] = $event->basePath . '/_extensions/s2_blog/admin.css';
        });

        $eventDispatcher->addListener(CustomMenuGeneratorEvent::class, function (CustomMenuGeneratorEvent $event) use ($container): void {
            $blogCommentProvider = $container->get(BlogCommentProvider::class);
            $size                = $blogCommentProvider->getPendingCommentsCount();

            if ($size > 0) {
                $event->addSignal('BlogComment', new Signal((string)$size, 'Blog new comments', '?entity=BlogComment&action=list&status=0&apply_filter=0'));
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
