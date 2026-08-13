<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Module\BaseModuleRegistry;
use S2\Cms\Extensions\ExtensionManager;

final class ModuleManagerCest
{
    public function baseModulesHaveNoOptionalLifecycle(\IntegrationTester $I): void
    {
        /** @var ExtensionManager $manager */
        $manager  = $I->grabAdminService(ExtensionManager::class);
        $registry = new BaseModuleRegistry();
        $list     = $manager->getExtensionList();

        $I->assertSame($registry->ids(), array_column($list['baseModules'], 'id'));
        $I->assertSame([], $list['availableExtensions']);
        $I->assertSame([], $list['installedExtensions']);
        $I->assertSame($registry->ids(), $manager->getEnabledExtensionIds());

        foreach ($registry->ids() as $id) {
            $I->assertNotNull($manager->flipExtension($id));
            $I->assertNotSame([], $manager->installExtension($id));
            $I->assertNotNull($manager->uninstallExtension($id));
        }

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Extension');
        $I->see('Built-in modules', 'h2');
        $I->assertCount(count($registry->ids()), $I->grabMultiple('.base-module'));
        $I->dontSeeElement('.base-module button');
    }
}
