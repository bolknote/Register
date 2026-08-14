<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Template;

use Codeception\Test\Unit;
use S2\Cms\AdminYard\CustomTemplateRenderer;
use S2\Cms\Asset\AssetPack;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Model\PermissionChecker;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomTemplateRendererColorSchemeTest extends Unit
{
    public function testSelectedStyleProvidesTheAdministrationColorScheme(): void
    {
        $tempDir = \sys_get_temp_dir() . '/register_admin_color_scheme_' . \bin2hex(\random_bytes(8));
        $styleDir = $tempDir . '/_styles/test';
        self::assertTrue(mkdir($styleDir, 0700, true));

        $template = $tempDir . '/template.php';
        $styleDefinition = $styleDir . '/test.php';
        self::assertNotFalse(file_put_contents($template, '<?php echo $styleColorScheme();'));
        self::assertNotFalse(file_put_contents(
            $styleDefinition,
            '<?php return (new ' . AssetPack::class . '(__DIR__))'
            . '->setColorScheme(' . AssetPack::class . '::COLOR_SCHEME_LIGHT);',
        ));

        try {
            $configProvider = new DynamicConfigProvider();
            $reflection = new \ReflectionClass($configProvider);
            $reflection->getProperty('params')->setValue($configProvider, ['S2_STYLE' => 'test']);

            $translator = self::createStub(TranslatorInterface::class);
            $translator->method('getLocale')->willReturn('en');

            $renderer = new CustomTemplateRenderer(
                $translator,
                $configProvider,
                new PermissionChecker(),
                new EventDispatcher(),
                '',
                $tempDir . '/',
            );

            self::assertSame('light', $renderer->render($template));
        } finally {
            @unlink($template);
            @unlink($styleDefinition);
            @rmdir($styleDir);
            @rmdir(\dirname($styleDir));
            @rmdir($tempDir);
        }
    }
}
