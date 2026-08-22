<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Template;

use Codeception\Test\Unit;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\Viewer;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ViewerSecurityTest extends Unit
{
    public function testTemplateVariablesCannotReplaceSelectedFileOrHelpers(): void
    {
        $rootDir = \sys_get_temp_dir() . '/register_viewer_security_' . \bin2hex(\random_bytes(8)) . '/';
        $viewDir = $rootDir . '_include/views/';
        self::assertTrue(mkdir($viewDir, 0700, true));

        $safeView = $viewDir . 'safe.php';
        $unsafeView = $rootDir . 'unsafe.php';
        self::assertNotFalse(file_put_contents(
            $safeView,
            '<?php echo "safe:" . $trans("message") . ":" . $date;',
        ));
        self::assertNotFalse(file_put_contents($unsafeView, '<?php echo "unsafe";'));

        try {
            $translator = self::createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnCallback(static fn(string $id): string => strtoupper($id));

            $viewer = new Viewer(
                $translator,
                new UrlBuilder('', '', ''),
                $rootDir,
                $this->styleProxy(),
                false,
            );

            self::assertSame('safe:MESSAGE:published-date', $viewer->render('safe', [
                '_found_file' => $unsafeView,
                'trans'       => 'not-callable',
                'date'        => 'published-date',
            ]));
        } finally {
            @unlink($safeView);
            @unlink($unsafeView);
            @rmdir($viewDir);
            @rmdir($rootDir . '_include');
            @rmdir($rootDir);
        }
    }

    private function styleProxy(): \Register\Core\Config\StringProxy
    {
        $provider = new DynamicConfigProvider();
        $reflection = new \ReflectionClass($provider);
        $reflection->getProperty('params')->setValue($provider, ['REGISTER_STYLE' => 'register']);

        return $provider->getStringProxy('REGISTER_STYLE');
    }
}
