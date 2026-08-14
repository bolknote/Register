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
}
