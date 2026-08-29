<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;
use Register\Core\Http\Cache\PageCacheGarbageCollector;
use Symfony\Component\Filesystem\Filesystem;

final class PageCacheGarbageCollectorTest extends TestCase
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_page_cache_gc_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory . '/pages/current', 0700, true);
        mkdir($this->temporaryDirectory . '/pages_v2/old', 0700, true);
        mkdir($this->temporaryDirectory . '/response_encoding', 0700, true);
        file_put_contents($this->temporaryDirectory . '/pages/current/item', 'current');
        file_put_contents($this->temporaryDirectory . '/pages_v2/old/first', 'old');
        file_put_contents($this->temporaryDirectory . '/pages_v2/old/second', 'old');
        file_put_contents($this->temporaryDirectory . '/response_encoding/item', 'compressed');
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testRemovesOnlyObsoleteGenerationsWithinThePerPassLimit(): void
    {
        $collector = new PageCacheGarbageCollector($this->temporaryDirectory, 'pages');

        self::assertSame(1, $collector->collect(1));
        self::assertDirectoryExists($this->temporaryDirectory . '/pages_v2');
        self::assertFileExists($this->temporaryDirectory . '/pages/current/item');
        self::assertFileExists($this->temporaryDirectory . '/response_encoding/item');

        self::assertGreaterThan(0, $collector->collect(128));
        self::assertSame(0, $collector->collect(128));

        self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/pages_v2');
        self::assertFileExists($this->temporaryDirectory . '/pages/current/item');
        self::assertFileExists($this->temporaryDirectory . '/response_encoding/item');
    }

    public function testRejectsANameOutsideThePageCacheNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PageCacheGarbageCollector($this->temporaryDirectory, '../pages');
    }
}
