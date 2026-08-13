<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Framework;

use Symfony\Component\Routing\RouteCollection;

/** A module that contributes public or administrative routes. */
interface RoutingModuleInterface extends ModuleInterface
{
    public function registerRoutes(RouteCollection $routes): void;
}
