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
use Register\Core\Http\Cache\VolatileCacheEncryptionKeyProvider;
use Symfony\Component\Filesystem\Filesystem;

final class VolatileCacheEncryptionKeyProviderTest extends TestCase
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_cache_key_' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testCreatesAndReusesDedicatedProtectedSecret(): void
    {
        [$provider, $store, $filename] = $this->services();

        $first  = $provider->get();
        $second = $provider->get();

        self::assertSame($first->key, $second->key);
        self::assertSame($first->fingerprint, $second->fingerprint);
        self::assertSame(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, \strlen($first->key));
        self::assertFileExists($filename);
        $permissions = fileperms($filename);
        self::assertIsInt($permissions);
        self::assertSame(0600, $permissions & 0777);
        self::assertNotNull($store->getExtensionPrivate(VolatileCacheEncryptionKeyProvider::SECRET_NAME));
    }

    public function testRejectsCorruptStoredKey(): void
    {
        [$provider, $store] = $this->services();
        $store->replaceExtensionPrivate(VolatileCacheEncryptionKeyProvider::SECRET_NAME, 'not-base64!');

        $this->expectException(\RuntimeException::class);
        $provider->get();
    }

    /** @return array{VolatileCacheEncryptionKeyProvider, DynamicSecretStore, string} */
    private function services(): array
    {
        $registry = new DynamicSecretParameterRegistry(['REGISTER_TEST_SECRET']);
        $registry->registerExtensionPrivate(VolatileCacheEncryptionKeyProvider::SECRET_NAME);

        $filename = $this->temporaryDirectory . '/config.secrets.php';
        $store = new DynamicSecretStore($filename, $registry);

        return [new VolatileCacheEncryptionKeyProvider($store), $store, $filename];
    }
}
