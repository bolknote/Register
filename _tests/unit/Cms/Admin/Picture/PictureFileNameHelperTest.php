<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Admin\Picture;

use Codeception\Test\Unit;
use S2\AdminYard\Translator;
use S2\Cms\Admin\Picture\PictureFileNameHelper;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class PictureFileNameHelperTest extends Unit
{
    private const string ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /** @var list<string> */
    private array $temporaryFiles = [];

    #[\Override]
    protected function _after(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testEmptyAllowListFailsClosed(): void
    {
        self::assertFalse($this->helper('')->isAllowedExtension('picture.png'));
    }

    public function testActiveAndCompoundExtensionsAreAlwaysForbidden(): void
    {
        $helper = $this->helper('config htaccess html ini jpg php svg');

        self::assertFalse($helper->isAllowedExtension('payload.php'));
        self::assertFalse($helper->isAllowedExtension('payload.php.jpg'));
        self::assertFalse($helper->isAllowedExtension('vector.svg'));
        self::assertFalse($helper->isAllowedExtension('web.config'));
        self::assertFalse($helper->isAllowedExtension('payload.user.ini'));
        self::assertFalse($helper->isAllowedExtension('.htaccess'));
        self::assertFalse($helper->isAllowedExtension('.jpg'));
    }

    public function testAcceptsAValidImageWithMatchingContent(): void
    {
        $this->expectNotToPerformAssertions();

        $path = $this->temporaryFile((string)base64_decode(self::ONE_PIXEL_PNG, true));
        $file = new UploadedFile($path, 'photo.png', 'image/png', null, true);

        $this->helper('png')->assertSafeUploadedFile($file, 'photo.png');
    }

    public function testRejectsContentThatDoesNotMatchTheExtension(): void
    {
        $path = $this->temporaryFile('This is not an image.');
        $file = new UploadedFile($path, 'photo.png', 'image/png', null, true);

        try {
            $this->helper('png')->assertSafeUploadedFile($file, 'photo.png');
            self::fail('A text file disguised as PNG must be rejected.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $runtimeException->getCode());
        }
    }

    public function testRejectsConfiguredExtensionsWithoutASafeMimeMapping(): void
    {
        $path = $this->temporaryFile('custom data');
        $file = new UploadedFile($path, 'file.custom', 'application/octet-stream', null, true);

        try {
            $this->helper('custom')->assertSafeUploadedFile($file, 'file.custom');
            self::fail('An extension without a MIME mapping must be rejected.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $runtimeException->getCode());
        }
    }

    public function testRejectsFilesLargerThanTheApplicationLimit(): void
    {
        $path = $this->temporaryFile('');
        $handle = fopen($path, 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(ftruncate($handle, PictureFileNameHelper::MAX_UPLOAD_BYTES + 1));
        fclose($handle);

        $file = new UploadedFile($path, 'large.zip', 'application/zip', null, true);

        try {
            $this->helper('zip')->assertSafeUploadedFile($file, 'large.zip');
            self::fail('An oversized file must be rejected.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $runtimeException->getCode());
        }
    }

    private function helper(string $allowedExtensions): PictureFileNameHelper
    {
        return new PictureFileNameHelper(new Translator([], 'en'), $allowedExtensions);
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'register_upload_');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $contents));
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
