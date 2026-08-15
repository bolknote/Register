<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\VisitorIdentity;

use Codeception\Test\Unit;

final class DistributionTest extends Unit
{
    public function testIdentityUsesFirstPartyBrowserRecoveryLayers(): void
    {
        $script = (string)file_get_contents('_assets/register/visitor/identity.js');

        self::assertStringContainsString('document.cookie', $script);
        self::assertStringContainsString('localStorage', $script);
        self::assertStringContainsString('indexedDB.open', $script);
        self::assertStringNotContainsString('FingerprintJS', $script);
        self::assertStringNotContainsString('fingerprint', $script);
        self::assertStringNotContainsString("createElement('script')", $script);
    }

    public function testIdentityModuleDoesNotPublishFingerprintingAssets(): void
    {
        $module = (string)file_get_contents('_include/src/Register/Module/VisitorIdentity/Module.php');

        self::assertStringNotContainsString('data-fingerprint-src', $module);
        self::assertStringNotContainsString('fp.min.js', $module);
        self::assertDirectoryDoesNotExist('_assets/register/visitor/vendor/fingerprintjs');
    }
}
