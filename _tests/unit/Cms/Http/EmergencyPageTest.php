<?php

declare(strict_types = 1);

namespace unit\Cms\Http;

use PHPUnit\Framework\TestCase;
use Register\Core\Http\EmergencyPage;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\Filesystem\Filesystem;

final class EmergencyPageTest extends TestCase
{
    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-emergency-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testResolvesRussianTextFromDiskAndSurvivesDamagedCache(): void
    {
        $root = \dirname(__DIR__, 4);
        $page = new EmergencyPage($root, $this->directory);
        self::assertSame('en', $page->text()['locale']);
        file_put_contents($this->directory . '/register_config.php', "<?php return ['REGISTER_LANGUAGE' => 'Russian'];");
        self::assertSame('ru', $page->text()['locale']);
        self::assertSame('Сайт временно недоступен', $page->text()['Emergency title']);
        file_put_contents($this->directory . '/register_config.php', '<?php invalid syntax !!');
        self::assertSame('en', $page->text()['locale']);
        file_put_contents($this->directory . '/register_config.php', "<?php return ['REGISTER_LANGUAGE' => '../../private'];");
        self::assertSame('en', $page->text()['locale']);
    }

    public function testRendersWithoutApplicationDatabaseOrDebugInstructions(): void
    {
        $root = \dirname(__DIR__, 4);
        file_put_contents($this->directory . '/register_config.php', "<?php return ['REGISTER_LANGUAGE' => 'Russian'];");
        $globals = $GLOBALS['registerBaseStaticParameters'] ?? null;
        $server = $_SERVER;
        try {
            $GLOBALS['registerBaseStaticParameters'] = ['base_path' => '/blog', 'cache_dir' => $this->directory];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/blog/search?q=%22%3E%3Cscript%3E&next=1';
            HtmlErrorRenderer::setTemplate($root . '/_include/views/error.php');
            $error = (new HtmlErrorRenderer(false))->render(new \RuntimeException('Private exception message'));
            self::assertSame(500, $error->getStatusCode());
            $html = $error->getAsString();
            self::assertStringContainsString('<html lang="ru">', $html);
            self::assertStringContainsString('Сайт временно недоступен', $html);
            self::assertStringContainsString('href="/blog/_assets/register/standalone.css?v=', $html);
            self::assertStringContainsString('q=%22%3E%3Cscript%3E&amp;next=1', $html);
            self::assertStringNotContainsString('DEBUG', $html);
            self::assertStringNotContainsString('Private exception message', $html);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            self::assertStringNotContainsString('q=', (new HtmlErrorRenderer(false))->render(new \RuntimeException())->getAsString());
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '//external.example/steal';
            self::assertStringNotContainsString('external.example', (new HtmlErrorRenderer(false))->render(new \RuntimeException())->getAsString());
        } finally {
            $_SERVER = $server;
            if ($globals === null) unset($GLOBALS['registerBaseStaticParameters']);
            else $GLOBALS['registerBaseStaticParameters'] = $globals;
        }
    }
}
