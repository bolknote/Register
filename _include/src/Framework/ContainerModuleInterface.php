<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Framework;

/** A module that contributes services to the application container. */
interface ContainerModuleInterface extends ModuleInterface
{
    public function buildContainer(Container $container): void;
}
