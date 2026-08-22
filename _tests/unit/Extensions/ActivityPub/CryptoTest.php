<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use phpseclib3\Crypt\RSA;
use S2\Cms\Config\DynamicSecretParameterRegistry;
use S2\Cms\Config\DynamicSecretStore;
use s2_extensions\activitypub\Domain\PublicIdGenerator;
use s2_extensions\activitypub\Security\ActivityPubSecret;
use s2_extensions\activitypub\Security\ActorKeyVault;
use s2_extensions\activitypub\Security\EncryptedPrivateKey;
use s2_extensions\activitypub\Security\RsaCrypto;
use Symfony\Component\Filesystem\Filesystem;

final class CryptoTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_activitypub_crypto_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        RSA::forceEngine();
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testGeneratesStableFormatPublicIdentifiers(): void
    {
        $generator = new PublicIdGenerator();
        $ids       = [];
        for ($index = 0; $index < 100; ++$index) {
            $id = $generator->generate();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/D', $id);
            $ids[$id] = true;
        }

        self::assertCount(100, $ids);
    }

    public function testPurePhpRsaAndSodiumVaultRoundTrip(): void
    {
        // This explicitly exercises the path available on shared hosting without ext-openssl.
        RSA::forceEngine('PHP');
        $crypto  = new RsaCrypto();
        $keyPair = $crypto->generateKeyPair();
        $payload = '(request-target): post /activitypub/inbox' . "\n" . 'host: social.example';

        $signature = $crypto->sign($keyPair->privateKeyPem, $payload);
        self::assertTrue($crypto->verify($keyPair->publicKeyPem, $payload, $signature));
        self::assertFalse($crypto->verify($keyPair->publicKeyPem, $payload . 'x', $signature));

        $registry = new DynamicSecretParameterRegistry(['CORE_TEST_SECRET']);
        $registry->registerExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $secretStore = new DynamicSecretStore(
            $this->temporaryDirectory . '/config.secrets.php',
            $registry,
        );
        $secretStore->getOrCreateExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $vault = new ActorKeyVault($secretStore);
        $keyId = (new PublicIdGenerator())->generate();

        $encrypted = $vault->encrypt($keyId, $keyPair->privateKeyPem);
        self::assertStringNotContainsString('PRIVATE KEY', $encrypted->ciphertext);
        self::assertSame($keyPair->privateKeyPem, $vault->decrypt($keyId, $encrypted));

        $this->expectException(\RuntimeException::class);
        $vault->decrypt(
            (new PublicIdGenerator())->generate(),
            new EncryptedPrivateKey($encrypted->ciphertext, $encrypted->nonce),
        );
    }

    public function testMissingMasterSecretNeverCreatesAReplacementIdentityKey(): void
    {
        $registry = new DynamicSecretParameterRegistry(['CORE_TEST_SECRET']);
        $registry->registerExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $filename = $this->temporaryDirectory . '/lost.secrets.php';
        $store    = new DynamicSecretStore($filename, $registry);
        $store->getOrCreateExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $vault     = new ActorKeyVault($store);
        $keyId     = (new PublicIdGenerator())->generate();
        $encrypted = $vault->encrypt($keyId, '-----BEGIN PRIVATE KEY-----test');
        (new Filesystem())->remove($filename);

        try {
            $vault->decrypt($keyId, $encrypted);
            self::fail('A missing ActivityPub master key must stop identity operations.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('identity recovery is required', $exception->getMessage());
        }

        self::assertFileDoesNotExist($filename);
    }
}
