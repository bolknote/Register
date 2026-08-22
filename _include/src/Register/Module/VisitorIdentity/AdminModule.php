<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use Register\Module\VisitorIdentity\Admin\DynamicConfigFormExtender;
use Register\Core\Admin\DynamicConfigFormExtenderInterface;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;

final class AdminModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(
            DynamicConfigFormExtender::class,
            new DynamicConfigFormExtender(),
            [DynamicConfigFormExtenderInterface::class],
        );
    }
}
