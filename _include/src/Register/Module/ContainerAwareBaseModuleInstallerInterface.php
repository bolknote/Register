<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module;

use S2\Cms\Framework\Container;
use S2\Cms\Pdo\DbLayer;

/** Creates a mandatory module schema using product services. */
interface ContainerAwareBaseModuleInstallerInterface extends BaseModuleManifestInterface
{
    public function installFresh(DbLayer $dbLayer, Container $container): void;
}
