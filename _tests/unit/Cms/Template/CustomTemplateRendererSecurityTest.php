<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Template;

use Codeception\Test\Unit;
use Register\Core\AdminYard\CustomTemplateRenderer;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Model\PermissionChecker;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomTemplateRendererSecurityTest extends Unit
{
    public function testTemplateDataCannotReplaceSelectedFileOrHelpers(): void
    {
        $tempDir = \sys_get_temp_dir() . '/register_admin_renderer_security_' . \bin2hex(\random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));

        $safeTemplate = $tempDir . '/safe.php';
        $unsafeTemplate = $tempDir . '/unsafe.php';
        self::assertNotFalse(file_put_contents(
            $safeTemplate,
            '<?php echo $basePath . ":" . $trans("message");',
        ));
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
                '_template_path'       => $unsafeTemplate,
                'basePath'             => '/attacker',
                'trans'                => 'not-callable',
            ]));
        } finally {
            @unlink($safeTemplate);
            @unlink($unsafeTemplate);
            @rmdir($tempDir);
        }
    }

    public function testAdminYardTemplatesAreReplacedWithCspSafeOverrides(): void
    {
        $root = dirname(__DIR__, 4) . '/';
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');
        $translator->method('trans')->willReturnArgument(0);
        $renderer = new CustomTemplateRenderer(
            $translator,
            new DynamicConfigProvider(),
            new PermissionChecker(),
            new EventDispatcher(),
            '',
            $root,
        );

        $output = $renderer->render($root . '_include/admin-yard/templates/list-actions.php.inc', [
            'row'         => ['virtual_write_access_control' => true],
            'rowActions'  => [['name' => 'delete']],
            'csrfToken'   => 'test-token',
            'entityName'  => 'Article',
            'primaryKey'  => ['id' => 42],
        ]);

        self::assertStringContainsString('<button type="button"', $output);
        self::assertStringContainsString('data-admin-delete', $output);
        self::assertStringContainsString('data-confirm="Delete record confirmation"', $output);
        self::assertStringContainsString('data-csrf-token="test-token"', $output);
        self::assertStringNotContainsString('list-action-delete-popup', $output);
        self::assertStringNotContainsString('onclick=', $output);
    }
}
