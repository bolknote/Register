<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\NativeHostResolver;
use Register\Module\LinkHealth\RemoteHostResolverUnavailable;
use Register\Module\LinkHealth\RemoteHostResolutionTimedOut;

final class NativeHostResolverTest extends Unit
{
    public function testReturnsLiteralAddressWithoutOpeningDnsTransport(): void
    {
        $resolver = new NativeHostResolver([]);

        self::assertSame(['2001:4860:4860::8888'], $resolver->resolve('[2001:4860:4860::8888]'));
    }

    public function testEnforcesDeadlineInsideTheWebProcessWithoutCli(): void
    {
        $errorCode    = 0;
        $errorMessage = '';
        $server = stream_socket_server(
            'udp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND,
        );
        self::assertIsResource($server);
        $endpoint = stream_socket_get_name($server, false);
        self::assertIsString($endpoint);
        $resolver  = new NativeHostResolver([$endpoint], 0.05);
        $startedAt = hrtime(true);

        try {
            $resolver->resolve('slow.example');
            self::fail('A silent DNS server must hit the hard deadline.');
        } catch (RemoteHostResolutionTimedOut) {
            self::assertLessThan(1.0, ((float)hrtime(true) - (float)$startedAt) / 1_000_000_000.0);
        } finally {
            fclose($server);
        }
    }

    public function testFailsClosedWithoutSystemNameServers(): void
    {
        $resolver = new NativeHostResolver([]);

        $this->expectException(RemoteHostResolverUnavailable::class);
        $resolver->resolve('example.test');
    }
}
