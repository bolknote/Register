<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Extensions;

use Register\Core\Framework\Container;
use Register\Core\Pdo\DbLayer;

/** Lets a stateful optional extension prevent identity-breaking generic disable operations. */
interface ExtensionDisableGuardInterface
{
    public function getDisableBlockReason(DbLayer $dbLayer, Container $container): ?string;
}
