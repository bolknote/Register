<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use Register\AdminYard\Translator;
use Register\Admin\AdminConfigExtenderInterface;
use Register\Admin\DynamicConfigFormExtenderInterface;
use Register\Admin\Event\RedirectFromPublicEvent;
use Register\Admin\TranslationProviderInterface;
use Register\Core\AdminYard\CustomTemplateRendererEvent;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Model\PermissionChecker;
use Register\Module\Blog\Admin\AdminConfigExtender;
use Register\Module\Blog\Admin\DynamicConfigFormExtender;
use Register\Module\Blog\Admin\PathToAdminEntityConverter;
use Register\Module\Blog\Admin\TranslationProvider;
use Register\Content\ContentChangeDispatcher;
use Register\Module\Blog\Model\BlogPageCache;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class AdminModule implements ContainerModuleInterface, ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(AdminConfigExtender::class, fn(Container $container): \Register\Module\Blog\Admin\AdminConfigExtender => new AdminConfigExtender(
            $container->get(PermissionChecker::class),
            $container->get(Translator::class),
            $container->get(\Register\Content\TagRepository::class),
            $container->get(ContentChangeDispatcher::class),
            $container->get(BlogPageCache::class),
            $container->getStringParameter('db_type'),
            $container->getStringParameter('db_prefix'),
        ), [AdminConfigExtenderInterface::class]);

        $container->set(DynamicConfigFormExtender::class, new DynamicConfigFormExtender(), [DynamicConfigFormExtenderInterface::class]);

        $container->set(TranslationProvider::class, new TranslationProvider(), [TranslationProviderInterface::class]);

        $container->set(PathToAdminEntityConverter::class, new PathToAdminEntityConverter());
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
