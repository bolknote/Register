<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Image;

use Codeception\Test\Unit;
use Register\Core\Image\ThumbnailGenerator;
use Register\Core\Queue\QueuePublisher;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ThumbnailGeneratorTest extends Unit
{
    private string $cacheDir = '';

    private ?\PDO $pdo = null;

    #[\Override]
    protected function _before(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/register_thumbnail_generator_' . \bin2hex(\random_bytes(4));
        \mkdir($this->cacheDir, 0777, true);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE queue (
    id VARCHAR(80) NOT NULL,
    code VARCHAR(80) NOT NULL,
    payload TEXT NOT NULL,
    generation INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    available_at INTEGER NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    failed_at INTEGER NULL,
    PRIMARY KEY (id, code)
)
SQL);
        $this->pdo = $pdo;
    }

    #[\Override]
    protected function _after(): void
    {
        $this->removeDir($this->cacheDir);
    }

    public function testUsesSeparateRegularAndRetinaThumbnails(): void
    {
        $src        = '/pictures/original.jpg';
        $regularUrl = $this->createCachedThumbnail($src, 120, 60);
        $retinaUrl  = $this->createCachedThumbnail($src, 240, 120);

        $html = $this->generator()->getThumbnailHtml($src, '400', '200', 120, 100);

        self::assertSame(
            '<img src="' . $regularUrl . '" srcset="' . $regularUrl . ' 1x, ' . $retinaUrl
            . ' 2x" width="120" height="60" alt="" loading="lazy" decoding="async">',
            $html,
        );
        $countStatement = $this->pdo()->query('SELECT COUNT(*) FROM queue');
        self::assertNotFalse($countStatement);
        self::assertSame(0, (int)$countStatement->fetchColumn());
    }

    public function testQueuesBothSizesAndFallsBackToTheOriginalUntilTheyAreReady(): void
    {
        $html = $this->generator()->getThumbnailHtml('/pictures/original.jpg', '400', '200', 120, 100);

        self::assertSame(
            '<img src="/pictures/original.jpg" width="120" height="60" alt="" loading="lazy" decoding="async">',
            $html,
        );
        $countStatement = $this->pdo()->query('SELECT COUNT(*) FROM queue');
        self::assertNotFalse($countStatement);
        self::assertSame(2, (int)$countStatement->fetchColumn());

        $payloadStatement = $this->pdo()->query('SELECT payload FROM queue ORDER BY payload');
        self::assertNotFalse($payloadStatement);
        $payloads = [];
        foreach ($payloadStatement->fetchAll(\PDO::FETCH_COLUMN) as $payload) {
            if (!\is_string($payload)) {
                throw new \UnexpectedValueException('Thumbnail queue payload must be a string.');
            }

            $payloads[] = \json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        }

        self::assertSame([
            ['/pictures/original.jpg', 120, 60],
            ['/pictures/original.jpg', 240, 120],
        ], $payloads);
    }

    private function generator(): ThumbnailGenerator
    {
        return new ThumbnailGenerator(
            new EventDispatcher(),
            new QueuePublisher($this->pdo(), ''),
            '/pictures',
            $this->cacheDir,
        );
    }

    private function pdo(): \PDO
    {
        return $this->pdo ?? throw new \LogicException('Thumbnail test database is not initialized.');
    }

    private function createCachedThumbnail(string $src, int $width, int $height): string
    {
        $hash         = \md5(\serialize([$src, $width, $height]));
        $relativePath = '/cache/' . \substr($hash, 0, 2) . '/' . \substr($hash, 2, 2) . '/'
            . \substr($hash, 4) . '.jpg';
        $filename     = $this->cacheDir . $relativePath;

        \mkdir(\dirname($filename), 0777, true);
        \touch($filename);

        return '/pictures' . $relativePath;
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $files = \scandir($dir);
        if ($files === false) {
            throw new \RuntimeException('Unable to scan temporary thumbnail directory.');
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (\is_dir($path)) {
                $this->removeDir($path);
            } else {
                \unlink($path);
            }
        }

        \rmdir($dir);
    }
}
