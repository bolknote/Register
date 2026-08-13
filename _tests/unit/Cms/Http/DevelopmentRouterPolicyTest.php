<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Http;

use Codeception\Test\Unit;
use S2\Cms\Http\DevelopmentRouterPolicy;

final class DevelopmentRouterPolicyTest extends Unit
{
    public function testAllowsAdminYardAssetsRequiredByAdminPages(): void
    {
        self::assertTrue(DevelopmentRouterPolicy::isAllowedStaticFile(
            '/_vendor/s2/admin-yard/demo/style.css',
            'css'
        ));
        self::assertTrue(DevelopmentRouterPolicy::isAllowedStaticFile(
            '/_vendor/s2/admin-yard/demo/script.js',
            'js'
        ));
    }

    public function testDoesNotExposeOtherVendorFiles(): void
    {
        self::assertFalse(DevelopmentRouterPolicy::isAllowedStaticFile(
            '/_vendor/s2/admin-yard/composer.json',
            'json'
        ));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedStaticFile(
            '/_vendor/example/package/style.css',
            'css'
        ));
    }

    public function testAllowsOnlyStaticFilesUnderPublicApplicationPrefixes(): void
    {
        self::assertTrue(DevelopmentRouterPolicy::isAllowedStaticFile('/_admin/css/admin-override.css', 'CSS'));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedStaticFile('/_admin/templates/layout.php.inc', 'inc'));
    }

    public function testAllowsOnlyKnownPhpEndpoints(): void
    {
        self::assertTrue(DevelopmentRouterPolicy::isAllowedPhpEndpoint('/_admin/index.php'));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedPhpEndpoint('/_include/config.php'));
    }
}
