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
    public function testSelfHostedFingerprintBuildPreservesItsMitLicense(): void
    {
        $directory = '_assets/register/visitor/vendor/fingerprintjs';

        self::assertFileExists($directory . '/fp.min.js');
        self::assertFileExists($directory . '/LICENSE');
        self::assertFileExists($directory . '/REGISTER.md');

        $bundle = (string)file_get_contents($directory . '/fp.min.js');
        $license = (string)file_get_contents($directory . '/LICENSE');
        $notice = (string)file_get_contents($directory . '/REGISTER.md');

        self::assertStringContainsString('FingerprintJS v5.2.0', $bundle);
        self::assertStringContainsString('Licensed under MIT License', $bundle);
        self::assertStringContainsString('Permission is hereby granted, free of charge', $license);
        self::assertStringContainsString('@fingerprintjs/fingerprintjs` 5.2.0', $notice);
    }

    public function testIdentityUsesAllThreeBrowserRecoveryLayers(): void
    {
        $script = (string)file_get_contents('_assets/register/visitor/identity.js');

        self::assertStringContainsString('document.cookie', $script);
        self::assertStringContainsString('localStorage', $script);
        self::assertStringContainsString('indexedDB.open', $script);
        self::assertStringContainsString('window.FingerprintJS.load', $script);
    }
}
