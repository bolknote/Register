<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Admin\Picture;

use Codeception\Test\Unit;
use Register\AdminYard\Translator;
use Register\Core\Admin\Picture\PictureStorageQuota;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class PictureStorageQuotaTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_upload_quota_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory . '/pictures', 0700, true));
        self::assertTrue(mkdir($this->directory . '/cache', 0700, true));
    }

    #[\Override]
    protected function _after(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testRejectsUploadThatWouldExceedStoredTotal(): void
    {
        self::assertNotFalse(file_put_contents($this->directory . '/pictures/existing.bin', '12345678'));
        $upload = $this->uploadedFile('abc');
        $stored = false;

        try {
            $this->quota(10)->store($upload, static function () use (&$stored): void {
                $stored = true;
            });
            self::fail('An upload over the total storage quota must be rejected.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $runtimeException->getCode());
            self::assertSame('Localized quota error', $runtimeException->getMessage());
        }

        self::assertFalse($stored);
    }

    public function testStoresUploadAndProtectsLockFile(): void
    {
        $upload = $this->uploadedFile('123');
        $target = $this->directory . '/pictures/stored.bin';

        $this->quota(10)->store($upload, static function () use ($upload, $target): void {
            $upload->move(dirname($target), basename($target));
        });

        self::assertSame('123', file_get_contents($target));
        $lock = $this->directory . '/cache/upload.lock';
        self::assertFileExists($lock);
        $permissions = fileperms($lock);
        self::assertNotFalse($permissions);
        self::assertSame(0600, $permissions & 0777);
    }

    public function testDoesNotFollowSymbolicLinksWhileCalculatingUsage(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Symbolic links are platform-dependent on Windows.');
        }

        $external = $this->directory . '/external.bin';
        self::assertNotFalse(file_put_contents($external, str_repeat('x', 100)));
        self::assertTrue(symlink($external, $this->directory . '/pictures/external-link.bin'));
        $upload = $this->uploadedFile('123');
        $stored = false;

        $this->quota(10)->store($upload, static function () use (&$stored): void {
            $stored = true;
        });

        self::assertTrue($stored);
    }

    private function quota(int $maximumBytes): PictureStorageQuota
    {
        return new PictureStorageQuota(
            new Translator(['Upload storage quota exceeded' => 'Localized quota error'], 'en'),
            $this->directory . '/pictures',
            $this->directory . '/cache/upload.lock',
            $maximumBytes,
        );
    }

    private function uploadedFile(string $contents): UploadedFile
    {
        $path = $this->directory . '/upload-' . bin2hex(random_bytes(4));
        self::assertNotFalse(file_put_contents($path, $contents));

        return new UploadedFile($path, 'upload.bin', 'application/octet-stream', null, true);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
