<?php
/**
 * Contract for an application module.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Framework;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouteCollection;

interface ModuleInterface
{
    public function buildContainer(Container $container): void;

    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void;

    public function registerRoutes(RouteCollection $routes, Container $container): void;
}
