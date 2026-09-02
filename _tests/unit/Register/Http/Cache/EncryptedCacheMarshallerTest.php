<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Register\Core\Http\Cache\CacheIntegrityException;
use Register\Core\Http\Cache\EncryptedCacheMarshaller;

final class EncryptedCacheMarshallerTest extends TestCase
{
    public function testRoundTripsValuesWithoutExposingPlaintext(): void
    {
        $marshaller = new EncryptedCacheMarshaller([$this->key()]);
        $value = [
            'private' => 'a-private-notification-token',
            'count'   => 17,
            'nested'  => ['visible' => true],
        ];

        $first  = $this->marshall($marshaller, $value);
        $second = $this->marshall($marshaller, $value);

        self::assertNotSame($first, $second, 'A fresh nonce must be used for every cache write.');
        self::assertStringNotContainsString('a-private-notification-token', $first);
        self::assertSame($value, $marshaller->unmarshall($first));
        self::assertSame($value, $marshaller->unmarshall($second));
    }

    /** @dataProvider tamperingProvider */
    #[DataProvider('tamperingProvider')]
    public function testRejectsEveryAuthenticatedPartThatWasTamperedWith(int $offset): void
    {
        $marshaller = new EncryptedCacheMarshaller([$this->key()]);
        $encrypted  = $this->marshall($marshaller, 'protected');
        $offset     += $offset < 0 ? \strlen($encrypted) : 0;
        $encrypted[$offset] = \chr(\ord($encrypted[$offset]) ^ 1);

        $this->expectException(CacheIntegrityException::class);
        $marshaller->unmarshall($encrypted);
    }

    /** @return iterable<string, array{int}> */
    public static function tamperingProvider(): iterable
    {
        yield 'format marker' => [0];
        yield 'nonce' => [18];
        yield 'ciphertext' => [-1];
    }

    public function testSupportsSafeKeyRotation(): void
    {
        $oldKey = $this->key();
        $newKey = $this->key();
        $oldMarshaller = new EncryptedCacheMarshaller([$oldKey]);
        $rotatingMarshaller = new EncryptedCacheMarshaller([$newKey, $oldKey]);

        self::assertSame('old value', $rotatingMarshaller->unmarshall(
            $this->marshall($oldMarshaller, 'old value'),
        ));

        $newValue = $this->marshall($rotatingMarshaller, 'new value');
        self::assertSame('new value', (new EncryptedCacheMarshaller([$newKey]))->unmarshall($newValue));

        $this->expectException(CacheIntegrityException::class);
        $oldMarshaller->unmarshall($newValue);
    }

    public function testRejectsInvalidKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncryptedCacheMarshaller(['too short']);
    }

    public function testRejectsEmptyKeyRing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncryptedCacheMarshaller([]);
    }

    private function key(): string
    {
        return random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }

    private function marshall(EncryptedCacheMarshaller $marshaller, mixed $value): string
    {
        $failed = null;
        $values = $marshaller->marshall(['key' => $value], $failed);

        self::assertSame([], $failed);
        self::assertArrayHasKey('key', $values);

        return $values['key'];
    }
}
