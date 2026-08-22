<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Install;

use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Config\InstallationConfigProvider;
use Register\Core\Framework\Container;
use Register\Core\Framework\ExtensionInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouteCollection;

class InstallExtension implements ExtensionInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->decorate(DynamicConfigProvider::class, static fn(Container $_container): \Register\Core\Config\InstallationConfigProvider => new InstallationConfigProvider());
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
    }
}
