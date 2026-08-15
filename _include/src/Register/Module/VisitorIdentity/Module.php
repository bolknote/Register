<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use S2\Cms\Asset\AssetPack;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Framework\RoutingModuleInterface;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Template\TemplateAssetEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class Module implements ContainerModuleInterface, ContainerAwareListenerModuleInterface, RoutingModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(VisitorIdentityRepository::class, static fn(Container $container): VisitorIdentityRepository => new VisitorIdentityRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(VisitorIdentityManager::class, static fn(Container $container): VisitorIdentityManager => new VisitorIdentityManager(
            $container->get(VisitorIdentityRepository::class),
            $container->get(DynamicConfigProvider::class),
            $container->getStringParameter('cookie_name'),
            $container->getStringParameter('base_path'),
        ));
        $container->set(JsonMutationGuard::class, new JsonMutationGuard());
        $container->set(ResolveVisitorController::class, static fn(Container $container): ResolveVisitorController => new ResolveVisitorController(
            $container->get(VisitorIdentityManager::class),
            $container->get(JsonMutationGuard::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $basePath        = rtrim($container->getStringParameter('base_path'), '/');
            $identityManager = $container->get(VisitorIdentityManager::class);
            $event->assetPack
                ->addMeta(sprintf(
                    '<meta name="register-visitor" data-cookie="%s" data-cookie-path="%s" data-resolve-url="%s">',
                    s2_htmlencode($identityManager->cookieName()),
                    s2_htmlencode($identityManager->cookiePath()),
                    s2_htmlencode($basePath . '/_visitor/resolve'),
                ))
                ->addJs($basePath . '/_assets/register/visitor/identity.js', [AssetPack::OPTION_DEFER])
            ;
        });
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->add('register_visitor_resolve', new Route(
            '/_visitor/resolve',
            ['_controller' => ResolveVisitorController::class],
            methods: ['POST'],
        ));
    }
}
