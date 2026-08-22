<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use Register\AdminYard\Translator;
use Register\Core\Admin\AdminConfigExtenderInterface;
use Register\Core\Admin\DynamicConfigFormExtenderInterface;
use Register\Core\Admin\Event\RedirectFromPublicEvent;
use Register\Core\Admin\TranslationProviderInterface;
use Register\Core\AdminYard\CustomTemplateRendererEvent;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Model\PermissionChecker;
use Register\Core\Model\TagsProvider;
use Register\Core\Pdo\DbLayer;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlGenerator;
use Register\Url\ContentUrlAliasRepository;
use Register\Content\Admin\ContentRevisionService;
use Register\Module\Blog\Admin\AdminConfigExtender;
use Register\Module\Blog\Admin\DynamicConfigFormExtender;
use Register\Module\Blog\Admin\PathToAdminEntityConverter;
use Register\Module\Blog\Admin\TranslationProvider;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentPublicationScheduler;
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
            $container->get(ContentUrlAliasRepository::class),
            $container->get(ContentChangeDispatcher::class),
            $container->get(ContentPublicationScheduler::class),
            $container->getStringParameter('db_type'),
            $container->getStringParameter('db_prefix'),
        ), [AdminConfigExtenderInterface::class]);

        $container->set(DynamicConfigFormExtender::class, new DynamicConfigFormExtender(), [DynamicConfigFormExtenderInterface::class]);

        $container->set(TranslationProvider::class, new TranslationProvider(), [TranslationProviderInterface::class]);

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
