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
        self::assertTrue(DevelopmentRouterPolicy::isAllowedStaticFile('/_assets/register/math/loader.js', 'js'));
        self::assertTrue(DevelopmentRouterPolicy::isAllowedStaticFile('/_assets/register/math/vendor/katex/fonts/KaTeX_Main-Regular.woff2', 'woff2'));
        self::assertTrue(DevelopmentRouterPolicy::isAllowedStaticFile('/_pictures/video.mov', 'mov'));
        self::assertTrue(DevelopmentRouterPolicy::isAllowedStaticFile('/_pictures/archive.zip', 'zip'));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedStaticFile('/_admin/templates/layout.php.inc', 'inc'));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedStaticFile('/_include/src/Register/ProductModule.php', 'php'));
    }

    public function testExposesOnlyGeneratedAssetBundlesFromCache(): void
    {
        self::assertTrue(DevelopmentRouterPolicy::isAllowedStaticFile(
            '/_cache/register_styles.1a2d1713.css',
            'css',
        ));
        self::assertTrue(DevelopmentRouterPolicy::isAllowedStaticFile(
            '/_cache/register_scripts.2dae6f3b.js.gz',
            'gz',
        ));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedStaticFile(
            '/_cache/register_styles.1a2d1713.css.meta.php',
            'php',
        ));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedStaticFile('/_cache/cache_config.php', 'php'));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedStaticFile('/_cache/phpstan/result.css', 'css'));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedStaticFile('/_cache/arbitrary.css', 'css'));
    }

    public function testAllowsOnlyKnownPhpEndpoints(): void
    {
        self::assertTrue(DevelopmentRouterPolicy::isAllowedPhpEndpoint('/_admin/index.php'));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedPhpEndpoint('/_extensions/s2_counter/data.php'));
        self::assertFalse(DevelopmentRouterPolicy::isAllowedPhpEndpoint('/_include/config.php'));
    }
}
