<?php

declare(strict_types = 1);

namespace unit\Register\Module\Blog;

use Codeception\Test\Unit;
use Register\Admin\Picture\PictureFileNameHelper;
use Register\Admin\Picture\PictureStorageQuota;
use Register\AdminYard\Translator;
use Register\Module\Blog\Inplace\PostInplaceMediaStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PostInplaceMediaStorageTest extends Unit
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==';

    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-post-media-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory . '/pictures/uploads', 0700, true));
    }

    #[\Override]
    protected function _after(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testUploadSkipsAnOccupiedDirectoryAcrossMediaExtensions(): void
    {
        self::assertTrue(mkdir($this->mediaPath('2026.01.02.png')));
        self::assertTrue(mkdir($this->mediaPath('2026.01.02.1@2x.jpg')));
        $stored = $this->storage()->storeCanonical($this->upload(), $this->date('2026-01-02'), false);
        self::assertSame('/uploads/2026.01.02.2.png', $stored);
        self::assertFileExists($this->directory . '/pictures' . $stored);
        self::assertDirectoryExists($this->mediaPath('2026.01.02.png'));
        self::assertDirectoryExists($this->mediaPath('2026.01.02.1@2x.jpg'));
    }

    public function testUploadDoesNotReplaceAnExistingOrBrokenSymbolicLink(): void
    {
        $this->requireLinks();
        $target = $this->directory . '/shared.png';
        self::assertNotFalse(file_put_contents($target, $this->png()));
        self::assertTrue(symlink($target, $this->mediaPath('2026.01.02.png')));
        $missing = $this->directory . '/missing.png';
        self::assertTrue(symlink($missing, $this->mediaPath('2026.01.02.1.png')));
        $stored = $this->storage()->storeCanonical($this->upload(), $this->date('2026-01-02'), false);
        self::assertSame('/uploads/2026.01.02.2.png', $stored);
        self::assertSame($target, readlink($this->mediaPath('2026.01.02.png')));
        self::assertSame($missing, readlink($this->mediaPath('2026.01.02.1.png')));
        self::assertSame($this->png(), file_get_contents($target));
    }

    public function testRedatingSkipsAnOccupiedLinkAndCanBeRolledBack(): void
    {
        $this->requireLinks();
        $storage = $this->storage();
        $original = $storage->storeCanonical($this->upload(), $this->date('2026-01-01'), false);
        $missing = $this->directory . '/missing.png';
        self::assertTrue(symlink($missing, $this->mediaPath('2026.01.02.png')));
        $move = $storage->redateCanonical($original, $this->date('2026-01-02'));
        self::assertSame(['from' => $original, 'to' => '/uploads/2026.01.02.1.png'], $move);
        self::assertSame($missing, readlink($this->mediaPath('2026.01.02.png')));
        $storage->rollbackRedate($move);
        self::assertFileExists($this->directory . '/pictures' . $original);
        self::assertFileDoesNotExist($this->directory . '/pictures' . $move['to']);
        self::assertSame($missing, readlink($this->mediaPath('2026.01.02.png')));
    }

    public function testRollbackDoesNotReplaceALinkCreatedAtTheOriginalPath(): void
    {
        $this->requireLinks();
        $storage = $this->storage();
        $original = $storage->storeCanonical($this->upload(), $this->date('2026-01-01'), false);
        $move = $storage->redateCanonical($original, $this->date('2026-01-02'));
        $missing = $this->directory . '/missing.png';
        $originalPath = $this->directory . '/pictures' . $original;
        self::assertTrue(symlink($missing, $originalPath));
        $storage->rollbackRedate($move);
        self::assertTrue(is_link($originalPath));
        self::assertSame($missing, readlink($originalPath));
        self::assertFileExists($this->directory . '/pictures' . $move['to']);
    }

    public function testNestedOperationsKeepTheNameLockUntilItsLastLeaseIsReleased(): void
    {
        $storage = $this->storage();
        $release = $storage->lockNames();
        $nestedRelease = $storage->lockNames();
        $contender = fopen($this->directory . '/cache/post-media-name.lock', 'c+b');
        self::assertIsResource($contender);
        try {
            self::assertFalse(flock($contender, LOCK_EX | LOCK_NB));
            $original = $storage->storeCanonical($this->upload(), $this->date('2026-01-01'), false);
            $move = $storage->redateCanonical($original, $this->date('2026-01-02'));
            $release();
            $release();
            self::assertFalse(flock($contender, LOCK_EX | LOCK_NB));
            $storage->rollbackRedate($move);
            self::assertFileExists($this->directory . '/pictures' . $original);
            self::assertFalse(flock($contender, LOCK_EX | LOCK_NB));
            $storage->delete($original);
            self::assertFileDoesNotExist($this->directory . '/pictures' . $original);
            self::assertFalse(flock($contender, LOCK_EX | LOCK_NB));
            $nestedRelease();
            self::assertTrue(flock($contender, LOCK_EX | LOCK_NB));
        } finally {
            $release();
            $nestedRelease();
            flock($contender, LOCK_UN);
            fclose($contender);
        }

        // A later request must acquire a fresh descriptor rather than retain a
        // closed handle from the preceding transaction.
        $laterRelease = $storage->lockNames();
        $laterRelease();
    }

    private function storage(): PostInplaceMediaStorage
    {
        $translator = new Translator([], 'en');

        return new PostInplaceMediaStorage(
            new PictureFileNameHelper($translator, 'png'),
            new PictureStorageQuota($translator, $this->directory . '/pictures', $this->directory . '/cache/quota.lock', 1_000_000),
            $translator,
            $this->directory . '/pictures',
            '/uploads',
            $this->directory . '/cache',
        );
    }

    private function upload(): UploadedFile
    {
        $source = $this->directory . '/source-' . bin2hex(random_bytes(4)) . '.png';
        self::assertNotFalse(file_put_contents($source, $this->png()));

        return new UploadedFile($source, 'image.png', 'image/png', null, true);
    }

    private function png(): string
    {
        $bytes = base64_decode(self::PNG, true);
        self::assertIsString($bytes);

        return $bytes;
    }

    private function mediaPath(string $name): string
    {
        return $this->directory . '/pictures/uploads/' . $name;
    }

    private function date(string $date): int
    {
        return (new \DateTimeImmutable($date . ' 12:00:00'))->getTimestamp();
    }

    private function requireLinks(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Symbolic links are platform-dependent on Windows.');
        }
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
