<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register;

use Register\Module\BaseModuleRegistry;
use Register\Core\Admin\AdminExtension;
use Register\Core\CmsExtension;
use Register\Core\Framework\Application;

/**
 * Registers the product modules that exist in every working Register installation.
 */
final readonly class RegisterKernel
{
    public function __construct(private BaseModuleRegistry $baseModuleRegistry)
    {
    }

    public function registerBaseModules(Application $application, bool $adminMode): void
    {
        $application->addModule(new CmsExtension());
        $application->addModule(new ProductModule($this->baseModuleRegistry));
        if ($adminMode) {
            $application->addModule(new AdminExtension());
        }

        foreach ($this->baseModuleRegistry->applicationModuleClasses() as $moduleClass) {
            $application->addModule(new $moduleClass());
        }

        if (!$adminMode) {
            return;
        }

        foreach ($this->baseModuleRegistry->adminModuleClasses() as $moduleClass) {
            $application->addModule(new $moduleClass());
        }
    }
}
