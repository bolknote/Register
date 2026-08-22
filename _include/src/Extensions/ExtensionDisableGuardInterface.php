<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Extensions;

use S2\Cms\Framework\Container;
use S2\Cms\Pdo\DbLayer;

/** Lets a stateful optional extension prevent identity-breaking generic disable operations. */
interface ExtensionDisableGuardInterface
{
    public function getDisableBlockReason(DbLayer $dbLayer, Container $container): ?string;
}
