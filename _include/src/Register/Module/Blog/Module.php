<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerAwareRoutingModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouteCollection;

final readonly class Module implements ContainerModuleInterface, ContainerAwareListenerModuleInterface, ContainerAwareRoutingModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        (new ServiceModule())->buildContainer($container);
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        (new ListenerModule())->registerListeners($eventDispatcher, $container);
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
        (new RoutingModule())->registerRoutes($routes, $container);
    }
}
