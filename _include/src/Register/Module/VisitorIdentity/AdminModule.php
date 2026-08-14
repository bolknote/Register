<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use Register\Module\VisitorIdentity\Admin\DynamicConfigFormExtender;
use S2\Cms\Admin\DynamicConfigFormExtenderInterface;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerModuleInterface;

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
