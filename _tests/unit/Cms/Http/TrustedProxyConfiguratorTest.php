<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Http;

use Codeception\Test\Unit;
use S2\Cms\Http\TrustedProxyConfigurator;
use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyConfiguratorTest extends Unit
{
    #[\Override]
    protected function _after(): void
    {
        TrustedProxyConfigurator::configure([]);
    }

    public function testIgnoresForwardedHeaderFromUntrustedPeer(): void
    {
        TrustedProxyConfigurator::configure(['10.0.0.0/8']);
        $request = Request::create('https://example.test/', server: [
            'REMOTE_ADDR'         => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
        ]);

        self::assertSame('203.0.113.10', $request->getClientIp());
    }

    public function testResolvesClientThroughTrustedProxyChain(): void
    {
        TrustedProxyConfigurator::configure(['10.0.0.0/8']);
        $request = Request::create('https://example.test/', server: [
            'REMOTE_ADDR'          => '10.0.0.3',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 10.0.0.2',
        ]);

        self::assertSame('198.51.100.20', $request->getClientIp());
    }

    public function testNormalizesConfiguredAddressWhitespace(): void
    {
        TrustedProxyConfigurator::configure([' 10.0.0.3 ']);
        $request = Request::create('https://example.test/', server: [
            'REMOTE_ADDR'          => '10.0.0.3',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
        ]);

        self::assertSame('198.51.100.20', $request->getClientIp());
    }

    public function testResolvesClientThroughTrustedIpv6Cidr(): void
    {
        TrustedProxyConfigurator::configure(['2001:db8:1234::/48']);
        $request = Request::create('https://example.test/', server: [
            'REMOTE_ADDR'          => '2001:db8:1234::10',
            'HTTP_X_FORWARDED_FOR' => '2001:db8:ffff::20',
        ]);

        self::assertSame('2001:db8:ffff::20', $request->getClientIp());
    }

    /** @dataProvider invalidProxyProvider */
    public function testRejectsInvalidTrustedProxy(string $proxy): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TrustedProxyConfigurator::configure([$proxy]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProxyProvider(): iterable
    {
        yield 'hostname' => ['proxy.example.test'];
        yield 'invalid IPv4 CIDR' => ['192.0.2.1/33'];
        yield 'invalid IPv6 CIDR' => ['2001:db8::1/129'];
        yield 'empty' => [''];
    }
}
