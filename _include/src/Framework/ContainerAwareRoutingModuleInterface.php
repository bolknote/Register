<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Framework;

use Symfony\Component\Routing\RouteCollection;

/** A module that needs application services while configuring routes. */
interface ContainerAwareRoutingModuleInterface extends ModuleInterface
{
    public function registerRoutes(RouteCollection $routes, Container $container): void;
}
