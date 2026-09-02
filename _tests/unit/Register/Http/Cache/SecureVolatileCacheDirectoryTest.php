<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;
use Register\Core\Http\Cache\SecureVolatileCacheDirectory;
use Symfony\Component\Filesystem\Filesystem;

final class SecureVolatileCacheDirectoryTest extends TestCase
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_secure_cache_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporaryDirectory, 0700, true));
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testCreatesRepairsAndRecreatesPrivateBoundary(): void
    {
        $path      = $this->temporaryDirectory . '/private';
        $directory = new SecureVolatileCacheDirectory($path);

        self::assertTrue($directory->ensure());
        $this->assertPrivateMode($path);

        self::assertTrue(chmod($path, 0777));
        self::assertTrue($directory->ensure());
        $this->assertPrivateMode($path);

        self::assertTrue(rmdir($path));
        self::assertTrue($directory->ensure());
        $this->assertPrivateMode($path);
    }

    public function testRejectsSymlinkBoundary(): void
    {
        $target = $this->temporaryDirectory . '/target';
        $link   = $this->temporaryDirectory . '/private';
        self::assertTrue(mkdir($target, 0700));
        self::assertTrue(symlink($target, $link));

        self::assertFalse((new SecureVolatileCacheDirectory($link))->ensure());
    }

    public function testPrunesOnlyRecognizedStalePageCacheNamespaces(): void
    {
        $directory = new SecureVolatileCacheDirectory($this->temporaryDirectory . '/private');
        self::assertTrue($directory->ensure());
        $active = 'pages_v4_1111111111111111';
        $stale  = 'pages_v3_2222222222222222';
        $foreign = 'do-not-remove';
        self::assertTrue(mkdir($directory->path . '/' . $stale));
        self::assertTrue(mkdir($directory->path . '/' . $foreign));
        file_put_contents($directory->path . '/' . $stale . '/entry', 'old');

        $directory->prunePageCacheNamespaces($active);

        self::assertDirectoryDoesNotExist($directory->path . '/' . $active);
        self::assertDirectoryDoesNotExist($directory->path . '/' . $stale);
        self::assertDirectoryExists($directory->path . '/' . $foreign);
    }

    private function assertPrivateMode(string $path): void
    {
        clearstatcache(true, $path);
        $permissions = fileperms($path);
        self::assertIsInt($permissions);
        self::assertSame(0700, $permissions & 0777);
    }
}
