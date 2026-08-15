<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Config;

use Codeception\Test\Unit;
use S2\Cms\Config\StaticConfigLoader;

final class StaticConfigLoaderTest extends Unit
{
    public function testDefaultMediaAllowlistSupportsModernFormatsWithoutActiveContent(): void
    {
        $extensions = explode(' ', StaticConfigLoader::DEFAULT_ALLOWED_EXTENSIONS);

        self::assertContains('avif', $extensions);
        self::assertContains('webp', $extensions);
        self::assertContains('mov', $extensions);
        self::assertContains('webm', $extensions);
        self::assertNotContains('php', $extensions);
        self::assertNotContains('svg', $extensions);
    }

    public function testNormalizesTrustedProxyString(): void
    {
        $method = new \ReflectionMethod(StaticConfigLoader::class, 'stringList');

        self::assertSame(
            ['10.0.0.0/8', '192.0.2.10', '2001:db8::/32'],
            $method->invoke(
                new StaticConfigLoader(),
                "10.0.0.0/8, 192.0.2.10\n2001:db8::/32",
            ),
        );
    }

    public function testNormalizesTrustedProxyArray(): void
    {
        $method = new \ReflectionMethod(StaticConfigLoader::class, 'stringList');

        self::assertSame(
            ['10.0.0.1', '2001:db8::1'],
            $method->invoke(new StaticConfigLoader(), [' 10.0.0.1 ', '', '2001:db8::1']),
        );
    }

    public function testRejectsNonStringTrustedProxy(): void
    {
        $method = new \ReflectionMethod(StaticConfigLoader::class, 'stringList');
        $this->expectException(\InvalidArgumentException::class);

        $method->invoke(new StaticConfigLoader(), ['10.0.0.1', 123]);
    }

    public function testBackupRetentionIsStrictlyBounded(): void
    {
        $method = new \ReflectionMethod(StaticConfigLoader::class, 'boundedInt');
        $loader = new StaticConfigLoader();

        self::assertSame(14, $method->invoke($loader, '14', 7, 1, 365));
        self::assertSame(7, $method->invoke($loader, 0, 7, 1, 365));
        self::assertSame(7, $method->invoke($loader, 366, 7, 1, 365));
        self::assertSame(7, $method->invoke($loader, '7 days', 7, 1, 365));
    }

    public function testBackupEncryptionKeyIsKeptOutsideDynamicConfiguration(): void
    {
        $method = new \ReflectionMethod(StaticConfigLoader::class, 'normalizeArrayConfig');
        $secret = str_repeat('ab', 32);

        $defaults = $method->invoke(new StaticConfigLoader(), []);
        self::assertIsArray($defaults);
        self::assertNull($defaults['backups']['encryption_key']);

        $configured = $method->invoke(
            new StaticConfigLoader(),
            ['backups' => ['encryption_key' => $secret]],
        );
        self::assertIsArray($configured);
        self::assertSame($secret, $configured['backups']['encryption_key']);
    }

    public function testBackupRecipientKeysRemainAvailableForOfflineRecovery(): void
    {
        $method = new \ReflectionMethod(StaticConfigLoader::class, 'normalizeArrayConfig');
        $loader = new StaticConfigLoader();
        $configured = $method->invoke($loader, [
            'backups' => [
                'recipient_public_key'  => 'public-key',
                'recipient_private_key' => 'private-key',
            ],
        ]);

        self::assertIsArray($configured);
        self::assertSame('public-key', $configured['backups']['recipient_public_key']);
        self::assertSame('private-key', $configured['backups']['recipient_private_key']);
    }

    public function testUploadQuotaHasASafeDefaultAndStrictBounds(): void
    {
        $method = new \ReflectionMethod(StaticConfigLoader::class, 'normalizeArrayConfig');
        $loader = new StaticConfigLoader();

        $defaultConfig = $method->invoke($loader, []);
        self::assertIsArray($defaultConfig);
        self::assertSame(
            StaticConfigLoader::DEFAULT_UPLOAD_QUOTA_BYTES,
            $defaultConfig['files']['upload_quota_bytes'],
        );

        $configuredQuota = StaticConfigLoader::MIN_UPLOAD_QUOTA_BYTES + 1;
        $configured = $method->invoke($loader, ['files' => ['upload_quota_bytes' => (string)$configuredQuota]]);
        self::assertIsArray($configured);
        self::assertSame($configuredQuota, $configured['files']['upload_quota_bytes']);

        $tooSmall = $method->invoke($loader, ['files' => ['upload_quota_bytes' => 1]]);
        self::assertIsArray($tooSmall);
        self::assertSame(
            StaticConfigLoader::DEFAULT_UPLOAD_QUOTA_BYTES,
            $tooSmall['files']['upload_quota_bytes'],
        );
    }

    public function testKeepsOptionalExternalMediaUrlSeparateFromTheStorageDirectory(): void
    {
        $method = new \ReflectionMethod(StaticConfigLoader::class, 'normalizeArrayConfig');
        $config = $method->invoke(new StaticConfigLoader(), [
            'files' => [
                'image_dir' => '/home/account/register-media',
                'image_url' => 'https://media.example.test',
            ],
        ]);

        self::assertIsArray($config);
        self::assertSame('/home/account/register-media', $config['files']['image_dir']);
        self::assertSame('https://media.example.test', $config['files']['image_url']);
    }
}
