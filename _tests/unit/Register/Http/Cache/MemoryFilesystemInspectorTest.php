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
use Symfony\Component\Filesystem\Filesystem;

final class MemoryFilesystemInspectorTest extends TestCase
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_mount_info_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporaryDirectory . '/memory/child', 0700, true));
        self::assertTrue(mkdir($this->temporaryDirectory . '/disk', 0700, true));
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testUsesTheMostSpecificMountAndRecognizesTmpfs(): void
    {
        $mountInfo = $this->temporaryDirectory . '/mountinfo';
        $root = $this->encodePath((string)realpath($this->temporaryDirectory));
        $memory = $this->encodePath((string)realpath($this->temporaryDirectory . '/memory'));
        file_put_contents($mountInfo, implode("\n", [
            "20 1 8:1 / {$root} rw - ext4 /dev/root rw",
            "21 20 0:42 / {$memory} rw,nosuid,nodev - tmpfs tmpfs rw",
        ]) . "\n");

        $inspector = new MemoryFilesystemInspector($mountInfo);

        self::assertTrue($inspector->isMemoryBacked($this->temporaryDirectory . '/memory/child'));
        self::assertFalse($inspector->isMemoryBacked($this->temporaryDirectory . '/disk'));
    }

    public function testReturnsUnknownWhenNoMountTableIsAvailable(): void
    {
        self::assertNull((new MemoryFilesystemInspector(
            $this->temporaryDirectory . '/missing',
        ))->isMemoryBacked($this->temporaryDirectory));
    }

    public function testRejectsANonexistentPath(): void
    {
        self::assertFalse((new MemoryFilesystemInspector(
            $this->temporaryDirectory . '/missing',
        ))->isMemoryBacked($this->temporaryDirectory . '/absent'));
    }

    private function encodePath(string $path): string
    {
        return str_replace(' ', '\\040', $path);
    }
}
