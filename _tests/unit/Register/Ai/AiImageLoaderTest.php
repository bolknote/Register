<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Ai;

use PHPUnit\Framework\TestCase;
use Register\Ai\Admin\AiImageLoader;
use Register\Ai\AiException;

final class AiImageLoaderTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor PHPUnit initializes this property in setUp(). */
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-ai-image-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $image = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        if ($image === false) {
            throw new \LogicException('Invalid embedded PNG fixture.');
        }

        file_put_contents(
            $this->directory . '/pixel.png',
            $image,
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        $filename = $this->directory . '/pixel.png';
        if (is_file($filename)) {
            unlink($filename);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testLoadsAnImageFromConfiguredPublicPath(): void
    {
        $image = (new AiImageLoader($this->directory, '/blog/_pictures'))->load('/blog/_pictures/pixel.png');

        self::assertSame('image/png', $image->mimeType);
        self::assertNotSame('', $image->data);
    }

    public function testLoadsAnImageFromConfiguredExternalMediaUrl(): void
    {
        $image = (new AiImageLoader($this->directory, 'https://cdn.example/media'))
            ->load('https://cdn.example/media/pixel.png');

        self::assertSame('image/png', $image->mimeType);
    }

    public function testLoadsVersionedEditorImagesWithoutIncludingTheQueryInTheFilePath(): void
    {
        foreach (['/blog/_pictures', 'https://cdn.example/media'] as $prefix) {
            $loader = new AiImageLoader($this->directory, $prefix);
            $original = $loader->load($prefix . '/pixel.png');
            $versioned = $loader->load($prefix . '/pixel.png?media=85');

            self::assertSame($original->mimeType, $versioned->mimeType);
            self::assertSame($original->data, $versioned->data);
        }
    }

    /** @dataProvider invalidSourceProvider */
    public function testRejectsSourcesOutsideMediaDirectory(string $source): void
    {
        $this->expectException(AiException::class);
        (new AiImageLoader($this->directory, '/blog/_pictures'))->load($source);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSourceProvider(): iterable
    {
        yield 'external host' => ['https://example.com/blog/_pictures/pixel.png'];
        yield 'sibling prefix' => ['/blog/_pictures-other/pixel.png'];
        yield 'encoded traversal' => ['/blog/_pictures/%2e%2e/secret.png'];
        yield 'encoded slash traversal' => ['/blog/_pictures/folder%2f..%2fsecret.png'];
        yield 'query string' => ['/blog/_pictures/pixel.png?download=1'];
        yield 'extra query parameter' => ['/blog/_pictures/pixel.png?media=85&download=1'];
        yield 'duplicate version' => ['/blog/_pictures/pixel.png?media=85&media=86'];
        yield 'invalid version' => ['/blog/_pictures/pixel.png?media=0'];
        yield 'versioned external host' => ['https://example.com/blog/_pictures/pixel.png?media=85'];
        yield 'versioned traversal' => ['/blog/_pictures/%2e%2e/secret.png?media=85'];
        yield 'versioned fragment' => ['/blog/_pictures/pixel.png?media=85#fragment'];
    }
}
