<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use S2\Cms\HttpClient\Remote\HostResolverInterface;
use S2\Cms\HttpClient\Remote\PublicAddressGuard;
use S2\Cms\HttpClient\Remote\RemoteHostResolutionFailed;
use S2\Cms\HttpClient\Remote\UnsafeRemoteAddress;

final class PublicAddressGuardTest extends Unit
{
    public function testReturnsPublicAddressForTransportPinning(): void
    {
        $guard = new PublicAddressGuard($this->resolver([
            'public.example' => ['93.184.216.34'],
        ]));

        self::assertSame('93.184.216.34', $guard->resolvePublicAddress('https://public.example/page'));
    }

    /**
     * @dataProvider unsafeAnswersProvider
     * @param list<string> $answers
     */
    public function testRejectsPrivateReservedAndMixedDnsAnswers(array $answers): void
    {
        $guard = new PublicAddressGuard($this->resolver(['unsafe.example' => $answers]));

        $this->expectException(UnsafeRemoteAddress::class);
        $guard->resolvePublicAddress('http://unsafe.example/');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function unsafeAnswersProvider(): iterable
    {
        yield 'loopback' => [['127.0.0.1']];
        yield 'private' => [['10.0.0.1']];
        yield 'link local metadata' => [['169.254.169.254']];
        yield 'IPv6 loopback' => [['::1']];
        yield 'carrier-grade NAT' => [['100.64.0.1']];
        yield 'benchmark network' => [['198.18.0.1']];
        yield 'IPv4 documentation' => [['203.0.113.1']];
        yield 'IPv6 documentation' => [['2001:db8::1']];
        yield 'mixed public and private' => [['93.184.216.34', '192.168.1.2']];
    }

    public function testDistinguishesMissingDnsFromUnsafeDns(): void
    {
        $guard = new PublicAddressGuard($this->resolver([]));

        $this->expectException(RemoteHostResolutionFailed::class);
        $guard->resolvePublicAddress('https://missing.example/');
    }

    /** @param array<string, list<string>> $answers */
    private function resolver(array $answers): HostResolverInterface
    {
        return new PublicAddressHostResolver($answers);
    }
}

/** @internal */
final readonly class PublicAddressHostResolver implements HostResolverInterface
{
    /** @param array<string, list<string>> $answers */
    public function __construct(private array $answers)
    {
    }

    /** @return list<string> */
    #[\Override]
    public function resolve(string $host, ?float $timeoutSeconds = null): array
    {
        return $this->answers[$host] ?? [];
    }
}
