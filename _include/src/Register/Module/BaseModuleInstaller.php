<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module;

use Register\Core\Framework\Container;
use Register\Core\Pdo\DbLayer;

/**
 * Creates the schema and defaults owned by Register's extension-backed base modules.
 *
 * This is deliberately separate from the optional-module manager. It is invoked by Register's
 * integer schema ledger, never by administrator-controlled extension lifecycle actions.
 */
final readonly class BaseModuleInstaller
{
    public function __construct(private BaseModuleRegistry $baseModuleRegistry)
    {
    }

    public function installFresh(DbLayer $dbLayer, Container $container): void
    {
        foreach ($this->baseModuleRegistry->ids() as $id) {
            $manifestClass = $this->baseModuleRegistry->manifestClass($id);
            $manifest      = new $manifestClass();

            if ($manifest instanceof ContainerAwareBaseModuleInstallerInterface) {
                $manifest->installFresh($dbLayer, $container);
            } elseif ($manifest instanceof BaseModuleInstallerInterface) {
                $manifest->installFresh($dbLayer);
            }
        }
    }
}
