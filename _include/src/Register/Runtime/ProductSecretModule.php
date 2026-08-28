<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Register\Core\Config\DynamicSecretProviderInterface;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;

/** Adds product-owned names to the Core dynamic-secret registry. */
final readonly class ProductSecretModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(
            ProductDynamicSecretProvider::class,
            new ProductDynamicSecretProvider(),
            [DynamicSecretProviderInterface::class],
        );
    }
}
