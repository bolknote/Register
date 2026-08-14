<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Template;

use Codeception\Test\Unit;
use S2\Cms\AdminYard\CustomTemplateRenderer;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Model\PermissionChecker;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomTemplateRendererSecurityTest extends Unit
{
    public function testTemplateDataCannotReplaceSelectedFileOrHelpers(): void
    {
        $tempDir = \sys_get_temp_dir() . '/s2_admin_renderer_security_' . \bin2hex(\random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));

        $safeTemplate = $tempDir . '/safe.php';
        $unsafeTemplate = $tempDir . '/unsafe.php';
        self::assertNotFalse(file_put_contents($safeTemplate, '<?php echo $basePath . ":" . $trans("message");'));
        self::assertNotFalse(file_put_contents($unsafeTemplate, '<?php echo "unsafe";'));

        try {
            $translator = self::createStub(TranslatorInterface::class);
            $translator->method('getLocale')->willReturn('en');
            $translator->method('trans')->willReturnCallback(static fn(string $id): string => strtoupper($id));

            $renderer = new CustomTemplateRenderer(
                $translator,
                new DynamicConfigProvider(),
                new PermissionChecker(),
                new EventDispatcher(),
                '/configured',
                $tempDir . '/',
            );

            self::assertSame('/configured:MESSAGE', $renderer->render($safeTemplate, [
                '_template_path' => $unsafeTemplate,
                'basePath'       => '/attacker',
                'trans'          => 'not-callable',
            ]));
        } finally {
            @unlink($safeTemplate);
            @unlink($unsafeTemplate);
            @rmdir($tempDir);
        }
    }
}
