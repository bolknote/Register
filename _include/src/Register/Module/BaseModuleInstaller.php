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

/**
 * Creates the schema and defaults owned by Register's extension-backed base modules.
 *
 * This is deliberately separate from the optional-module manager: callers use it only while
 * creating a new Register installation. Product migrations will replace manifest versions later.
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
            (new $manifestClass())->install($dbLayer, $container, null);
        }
    }
}
