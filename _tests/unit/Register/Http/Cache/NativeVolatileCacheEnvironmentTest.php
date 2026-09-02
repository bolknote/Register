<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;
use Register\Core\Http\Cache\MemoryFilesystemInspector;
use Register\Core\Http\Cache\NativeVolatileCacheEnvironment;
use Symfony\Component\Filesystem\Filesystem;

final class NativeVolatileCacheEnvironmentTest extends TestCase
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_cache_environment_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporaryDirectory . '/memory', 0700, true));
        self::assertTrue(mkdir($this->temporaryDirectory . '/application', 0700, true));
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testCreatesAnApplicationAndUserIsolatedDirectoryOnTmpfs(): void
    {
        $mountInfo = $this->mountInfo('tmpfs');
        $environment = new NativeVolatileCacheEnvironment(
            [$this->temporaryDirectory . '/memory'],
            new MemoryFilesystemInspector($mountInfo),
        );

        $directory = $environment->tmpfsDirectory($this->temporaryDirectory . '/application');

        self::assertNotNull($directory);
        self::assertStringStartsWith(
            (string)realpath($this->temporaryDirectory . '/memory') . '/register-cache-',
            $directory->path,
        );
        self::assertTrue($directory->ensure());
        $permissions = fileperms($directory->path);
        self::assertIsInt($permissions);
        self::assertSame(0700, $permissions & 0777);
    }

    public function testRejectsDiskBackedCandidate(): void
    {
        $environment = new NativeVolatileCacheEnvironment(
            [$this->temporaryDirectory . '/memory'],
            new MemoryFilesystemInspector($this->mountInfo('ext4')),
        );

        self::assertNull($environment->tmpfsDirectory($this->temporaryDirectory . '/application'));
    }

    private function mountInfo(string $type): string
    {
        $filename = $this->temporaryDirectory . '/mountinfo-' . $type;
        $mount = str_replace(' ', '\\040', (string)realpath($this->temporaryDirectory . '/memory'));
        file_put_contents($filename, "21 20 0:42 / {$mount} rw - {$type} source rw\n");

        return $filename;
    }
}
