<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;
use Register\Core\Config\DynamicSecretParameterRegistry;
use Register\Core\Config\DynamicSecretStore;
use Register\Core\Http\Cache\PageCachePoolFactory;
use Register\Core\Http\Cache\SecureVolatileCacheDirectory;
use Register\Core\Http\Cache\VolatileCacheEncryptionKeyProvider;
use Register\Core\Http\Cache\VolatileCacheEnvironmentInterface;
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
        self::assertSame($this->temporaryDirectory . '/pages_v4', $pools->filesystemDirectory);

        $expected = bin2hex(random_bytes(4));
        self::assertSame($expected, $pools->persistent->get(
            'test-key',
            static function (ItemInterface $item) use ($expected): string {
                $item->expiresAfter(60);

                return $expected;
            },
        ));
        self::assertDirectoryExists($this->temporaryDirectory . '/pages_v4');
        self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/config');
    }

    public function testUsesVersionedNamespaceForCurrentCacheAbi(): void
    {
        self::assertSame('pages_v4', PageCachePoolFactory::filesystemNamespace());
        self::assertSame('pages', PageCachePoolFactory::namespaceForAbi(1));
        self::assertSame('pages_v2', PageCachePoolFactory::namespaceForAbi(2));
        self::assertSame('pages_v3', PageCachePoolFactory::namespaceForAbi(3));
        self::assertSame('pages_v4', PageCachePoolFactory::namespaceForAbi(4));
    }

    public function testUsesEncryptedTmpfsWithDurableWriteThrough(): void
    {
        [$factory, $secureDirectory] = $this->tmpfsFactory();
        $pools = $factory->create($this->temporaryDirectory . '/cache', dirname(__DIR__, 5));
        $value = 'private-value-' . bin2hex(random_bytes(8));

        self::assertFalse($pools->sharedMemoryEnabled);
        self::assertNotNull($pools->tmpfsDirectory);
        self::assertTrue($pools->volatileEncryptionEnabled);
        self::assertNotSame($pools->persistent, $pools->hot);
        self::assertSame($value, $this->cacheValue($pools->hot, 'notification', $value));
        self::assertSame($value, $this->cacheValue($pools->persistent, 'notification', 'wrong'));

        $files = $this->cacheFiles($pools->tmpfsDirectory);
        self::assertCount(1, $files);
        self::assertStringNotContainsString($value, (string)file_get_contents($files[0]));
        $this->assertPrivateMode($secureDirectory->path);
    }

    public function testTamperedTmpfsEntryFallsThroughAndRepairsFromDurableTier(): void
    {
        [$factory] = $this->tmpfsFactory();
        $pools = $factory->create($this->temporaryDirectory . '/cache', dirname(__DIR__, 5));
        $expected = 'durable-' . bin2hex(random_bytes(8));
        self::assertSame($expected, $this->cacheValue($pools->hot, 'tampered', $expected));
        $files = $this->cacheFiles((string)$pools->tmpfsDirectory);
        self::assertCount(1, $files);

        $contents = (string)file_get_contents($files[0]);
        $contents[-1] = \chr(\ord($contents[-1]) ^ 1);
        self::assertSame(\strlen($contents), file_put_contents($files[0], $contents));

        $callbackCalled = false;
        $actual = $pools->hot->get('tampered', static function () use (&$callbackCalled): string {
            $callbackCalled = true;

            return 'callback-must-not-run';
        });

        self::assertSame($expected, $actual);
        self::assertFalse($callbackCalled);
        $repaired = $this->cacheFiles((string)$pools->tmpfsDirectory);
        self::assertCount(1, $repaired);
        self::assertNotSame($contents, (string)file_get_contents($repaired[0]));
    }

    public function testDisappearingTmpfsIsRecreatedPrivatelyAndRepopulated(): void
    {
        [$factory, $secureDirectory] = $this->tmpfsFactory();
        $pools = $factory->create($this->temporaryDirectory . '/cache', dirname(__DIR__, 5));
        $expected = 'survives-' . bin2hex(random_bytes(8));
        self::assertSame($expected, $this->cacheValue($pools->hot, 'survivor', $expected));
        (new Filesystem())->remove($secureDirectory->path);

        $callbackCalled = false;
        $actual = $pools->hot->get('survivor', static function () use (&$callbackCalled): string {
            $callbackCalled = true;

            return 'wrong';
        });

        self::assertSame($expected, $actual);
        self::assertFalse($callbackCalled);
        self::assertDirectoryExists($secureDirectory->path);
        $this->assertPrivateMode($secureDirectory->path);
        self::assertCount(1, $this->cacheFiles((string)$pools->tmpfsDirectory));
    }

    public function testKeyRotationUsesANewNamespaceAndFallsThroughToDurableTier(): void
    {
        [$provider, $store] = $this->keyServices();
        $secureDirectory = new SecureVolatileCacheDirectory($this->temporaryDirectory . '/volatile');
        $environment = new FixedVolatileCacheEnvironment(false, $secureDirectory);
        $firstFactory = new PageCachePoolFactory(null, $environment, $provider);
        $firstPools = $firstFactory->create($this->temporaryDirectory . '/cache', dirname(__DIR__, 5));
        $expected = 'rotated-' . bin2hex(random_bytes(8));
        self::assertSame($expected, $this->cacheValue($firstPools->hot, 'rotation', $expected));
        $oldDirectory = (string)$firstPools->tmpfsDirectory;
        self::assertDirectoryExists($oldDirectory);

        $store->replaceExtensionPrivate(
            VolatileCacheEncryptionKeyProvider::SECRET_NAME,
            sodium_bin2base64(
                random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
                SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
            ),
        );
        $secondPools = (new PageCachePoolFactory(null, $environment, $provider))->create(
            $this->temporaryDirectory . '/cache',
            dirname(__DIR__, 5),
        );

        self::assertNotSame($oldDirectory, $secondPools->tmpfsDirectory);
        self::assertDirectoryDoesNotExist($oldDirectory);
        $callbackCalled = false;
        self::assertSame($expected, $secondPools->hot->get(
            'rotation',
            static function () use (&$callbackCalled): string {
                $callbackCalled = true;

                return 'wrong';
            },
        ));
        self::assertFalse($callbackCalled);
        self::assertCount(1, $this->cacheFiles((string)$secondPools->tmpfsDirectory));
    }

    public function testDoesNotCreateEncryptionSecretWithoutAVolatileBackend(): void
    {
        [$provider, , $secretFilename] = $this->keyServices();
        $factory = new PageCachePoolFactory(
            null,
            new FixedVolatileCacheEnvironment(false, null),
            $provider,
        );

        $pools = $factory->create($this->temporaryDirectory . '/cache', dirname(__DIR__, 5));

        self::assertSame($pools->persistent, $pools->hot);
        self::assertFalse($pools->volatileEncryptionEnabled);
        self::assertFileDoesNotExist($secretFilename);
    }

    /** @return array{PageCachePoolFactory, SecureVolatileCacheDirectory} */
    private function tmpfsFactory(): array
    {
        [$provider] = $this->keyServices();
        $secureDirectory = new SecureVolatileCacheDirectory($this->temporaryDirectory . '/volatile');
        $environment = new FixedVolatileCacheEnvironment(false, $secureDirectory);

        return [new PageCachePoolFactory(null, $environment, $provider), $secureDirectory];
    }

    /** @return array{VolatileCacheEncryptionKeyProvider, DynamicSecretStore, string} */
    private function keyServices(): array
    {
        $registry = new DynamicSecretParameterRegistry(['REGISTER_TEST_SECRET']);
        $registry->registerExtensionPrivate(VolatileCacheEncryptionKeyProvider::SECRET_NAME);

        $secretFilename = $this->temporaryDirectory . '/config.secrets.php';
        $store = new DynamicSecretStore($secretFilename, $registry);

        return [new VolatileCacheEncryptionKeyProvider($store), $store, $secretFilename];
    }

    private function cacheValue(\Symfony\Contracts\Cache\CacheInterface $cache, string $key, string $value): string
    {
        return $cache->get($key, static function (ItemInterface $item) use ($value): string {
            $item->expiresAfter(60);

            return $value;
        });
    }

    private function assertPrivateMode(string $path): void
    {
        $permissions = fileperms($path);
        self::assertIsInt($permissions);
        self::assertSame(0700, $permissions & 0777);
    }

    /** @return list<string> */
    private function cacheFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && !$entry->isLink()) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }
}

final readonly class FixedVolatileCacheEnvironment implements VolatileCacheEnvironmentInterface
{
    public function __construct(
        private bool $apcuAvailable,
        private ?SecureVolatileCacheDirectory $tmpfsDirectory,
    ) {
    }

    #[\Override]
    public function apcuAvailable(): bool
    {
        return $this->apcuAvailable;
    }

    #[\Override]
    public function tmpfsDirectory(string $applicationRoot): ?SecureVolatileCacheDirectory
    {
        return $this->tmpfsDirectory;
    }
}
