<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module;

use S2\Cms\Pdo\DbLayer;

/** Creates the fresh schema owned by a mandatory module. */
interface BaseModuleInstallerInterface extends BaseModuleManifestInterface
{
    public function installFresh(DbLayer $dbLayer): void;
}
