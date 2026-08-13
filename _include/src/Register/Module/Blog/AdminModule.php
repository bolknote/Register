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
use S2\Cms\Comment\Antispam\SpamFeedbackService;
use S2\Cms\AdminYard\CustomMenuGeneratorEvent;
use S2\Cms\AdminYard\CustomTemplateRendererEvent;
use S2\Cms\AdminYard\Signal;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ModuleInterface;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Model\TagsProvider;
use S2\Cms\Pdo\DbLayer;
use Register\Url\UniqueSlugGenerator;
use Register\Module\Blog\Admin\AdminConfigExtender;
use Register\Module\Blog\Admin\DashboardBlogProvider;
use Register\Module\Blog\Admin\DynamicConfigFormExtender;
use Register\Module\Blog\Admin\PathToAdminEntityConverter;
use Register\Module\Blog\Admin\TranslationProvider;
use Register\Module\Blog\Model\BlogCommentNotifier;
use Register\Comment\CommentRepository;
use Register\Content\ContentType;
use Register\Module\Blog\Model\PostProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouteCollection;

final class AdminModule implements ModuleInterface
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
            $container->get(BlogUrlBuilder::class),
            $container->get(BlogCommentNotifier::class),
            $container->get(SpamFeedbackService::class),
            $container->get(UniqueSlugGenerator::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->getStringParameter('db_type'),
            $container->getStringParameter('db_prefix'),
        ), [AdminConfigExtenderInterface::class]);

        $container->set(DynamicConfigFormExtender::class, fn(Container $_container): \Register\Module\Blog\Admin\DynamicConfigFormExtender => new DynamicConfigFormExtender(), [DynamicConfigFormExtenderInterface::class]);

        $container->set(TranslationProvider::class, fn(Container $_container): \Register\Module\Blog\Admin\TranslationProvider => new TranslationProvider(), [TranslationProviderInterface::class]);

        $container->set(DashboardBlogProvider::class, fn(Container $container): \Register\Module\Blog\Admin\DashboardBlogProvider => new DashboardBlogProvider(
            $container->get(TemplateRenderer::class),
            $container->get(DbLayer::class),
        ), [DashboardStatProviderInterface::class]);

        $container->set(PathToAdminEntityConverter::class, fn(Container $container): \Register\Module\Blog\Admin\PathToAdminEntityConverter => new PathToAdminEntityConverter(
            $container->get(DbLayer::class),
            $container->get(BlogUrlBuilder::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(CustomTemplateRendererEvent::class, function (CustomTemplateRendererEvent $event) : void {
            $event->extraStyles[] = $event->basePath . '/_assets/register/blog/admin.css';
        });

        $eventDispatcher->addListener(CustomMenuGeneratorEvent::class, function (CustomMenuGeneratorEvent $event) use ($container): void {
            $size = $container->get(CommentRepository::class)->countPending(ContentType::POST);

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
