<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;
use Register\Core\Http\Cache\PageCachePoolFactory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\ItemInterface;

final class PageCachePoolFactoryTest extends TestCase
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_page_cache_' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testUsesAnIsolatedFilesystemFallbackInCli(): void
    {
        $pools = (new PageCachePoolFactory())->create(
            $this->temporaryDirectory,
            dirname(__DIR__, 5),
        );

        self::assertFalse($pools->sharedMemoryEnabled);
        self::assertNull($pools->sharedMemoryNamespace);
        self::assertSame($pools->persistent, $pools->hot);
        self::assertSame($this->temporaryDirectory . '/pages', $pools->filesystemDirectory);

        $expected = bin2hex(random_bytes(4));
        self::assertSame($expected, $pools->persistent->get(
            'test-key',
            static function (ItemInterface $item) use ($expected): string {
                $item->expiresAfter(60);

                return $expected;
            },
        ));
        self::assertDirectoryExists($this->temporaryDirectory . '/pages');
        self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/config');
    }

    public function testKeepsTheOriginalFilesystemNamespaceForTheFirstCacheAbi(): void
    {
        self::assertSame('pages', PageCachePoolFactory::filesystemNamespace());
        self::assertSame('pages', PageCachePoolFactory::namespaceForAbi(1));
        self::assertSame('pages_v2', PageCachePoolFactory::namespaceForAbi(2));
    }
}
