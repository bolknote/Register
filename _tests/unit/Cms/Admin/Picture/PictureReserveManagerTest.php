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
use Register\Core\Admin\Picture\PictureFileNameHelper;
use Register\Core\Admin\Picture\PictureReserveManager;
use Symfony\Component\HttpFoundation\Response;

final class PictureReserveManagerTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . '/register-picture-reserve-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryDirectory, 0700, true));
        $this->temporaryDirectory = $temporaryDirectory;
    }

    #[\Override]
    protected function _after(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testCanonicalReservationSharesOrdinalsAcrossDensityAndExtension(): void
    {
        $manager = $this->manager();

        $first = $manager->reserveCanonicalImageFileName('/bolknote/images', '2026-08-26', 'webp', true);
        $second = $manager->reserveCanonicalImageFileName('/bolknote/images', '2026-08-26', 'jpg', false);

        self::assertSame('2026.08.26@2x.webp', $first['name']);
        self::assertSame('2026.08.26.1.jpg', $second['name']);
        self::assertNotSame($first['token'], $second['token']);
    }

    public function testCanonicalReservationAccountsForExistingFilesAndReservationGaps(): void
    {
        $imageDirectory = $this->temporaryDirectory . '/images/bolknote/images';
        self::assertTrue(mkdir($imageDirectory, 0700, true));
        self::assertNotFalse(file_put_contents($imageDirectory . '/2026.08.26.png', 'existing'));
        self::assertNotFalse(file_put_contents($imageDirectory . '/2026.08.26.2@2x.jpg', 'existing'));

        $reserve = $this->manager()->reserveCanonicalImageFileName(
            '/bolknote/images',
            '2026-08-26',
            'png',
            false,
        );

        self::assertSame('2026.08.26.1.png', $reserve['name']);
    }

    public function testCanonicalReservationRejectsInvalidPublicationDate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->manager()->reserveCanonicalImageFileName('', '2026-02-30', 'webp', true);
    }

    private function manager(): PictureReserveManager
    {
        return new PictureReserveManager(
            new PictureFileNameHelper(new Translator([], 'en'), 'jpg png webp'),
            $this->temporaryDirectory . '/images',
            $this->temporaryDirectory . '/cache',
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \FilesystemIterator($directory) as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new \LogicException('FilesystemIterator must return file information.');
            }

            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
                continue;
            }

            self::assertTrue(unlink($item->getPathname()));
        }

        self::assertTrue(rmdir($directory));
    }
}
