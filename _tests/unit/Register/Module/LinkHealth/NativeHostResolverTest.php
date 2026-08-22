<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use S2\Cms\HttpClient\Remote\NativeHostResolver;
use S2\Cms\HttpClient\Remote\RemoteHostResolverUnavailable;
use S2\Cms\HttpClient\Remote\RemoteHostResolutionTimedOut;

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

    public function testRetransmitsLostDnsDatagramsWithinTheSameHardDeadline(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('The retransmission fixture requires proc_open.');
        }

        $process = proc_open(
            [PHP_BINARY, '-r', $this->dnsRetryServerScript()],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        self::assertCount(3, $pipes);
        fclose($pipes[0]);
        $endpoint = trim((string)fgets($pipes[1]));
        self::assertNotSame('', $endpoint);

        $resolver = new NativeHostResolver([$endpoint], 0.8);
        try {
            self::assertSame([], $resolver->resolve('retry.example'));
        } finally {
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        }

        self::assertSame('', $error);
        self::assertSame(0, $exitCode);
    }

    public function testFailsClosedWithoutSystemNameServers(): void
    {
        $resolver = new NativeHostResolver([]);

        $this->expectException(RemoteHostResolverUnavailable::class);
        $resolver->resolve('example.test');
    }

    private function dnsRetryServerScript(): string
    {
        return <<<'PHP'
$errorCode = 0;
$errorMessage = '';
$server = stream_socket_server(
    'udp://127.0.0.1:0',
    $errorCode,
    $errorMessage,
    STREAM_SERVER_BIND,
);
if (!is_resource($server)) {
    fwrite(STDERR, $errorMessage);
    exit(1);
}
stream_set_timeout($server, 2);
echo stream_socket_get_name($server, false), PHP_EOL;
flush();
for ($requestNumber = 1; $requestNumber <= 4; ++$requestNumber) {
    $peer = null;
    $request = stream_socket_recvfrom($server, 4096, 0, $peer);
    if (!is_string($request) || strlen($request) < 13 || !is_string($peer) || $peer === '') {
        exit(2);
    }
    if ($requestNumber <= 2) {
        continue;
    }
    $header = unpack('nid', substr($request, 0, 2));
    if (!is_array($header)) {
        exit(3);
    }
    $response = pack('nnnnnn', $header['id'], 0x8180, 1, 0, 0, 0) . substr($request, 12);
    if (stream_socket_sendto($server, $response, 0, $peer) !== strlen($response)) {
        exit(4);
    }
}
fclose($server);
PHP;
    }
}
