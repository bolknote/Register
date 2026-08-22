<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\HttpClient\Remote;

/** Resolves once, rejects mixed/private answers, and returns the address to pin in the transport. */
final readonly class PublicAddressGuard
{
    /** Ranges that PHP's NO_PRIV_RANGE/NO_RES_RANGE flags do not reject consistently. */
    private const array NON_PUBLIC_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
    ];

    public function __construct(private HostResolverInterface $hostResolver)
    {
    }

    public function resolvePublicAddress(string $url, ?float $timeoutSeconds = null): string
    {
        $parsed = parse_url($url);
        if (!\is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            throw new UnsafeRemoteAddress('The remote URL has no HTTP host.');
        }

        $scheme = strtolower($parsed['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true) || isset($parsed['user']) || isset($parsed['pass'])) {
            throw new UnsafeRemoteAddress('Only credential-free HTTP and HTTPS URLs can be requested.');
        }

        $host      = trim($parsed['host'], '[]');
        $addresses = $this->hostResolver->resolve($host, $timeoutSeconds);
        if ($addresses === []) {
            throw new RemoteHostResolutionFailed('The remote host cannot be resolved.');
        }

        foreach ($addresses as $address) {
            if (!$this->isPublicAddress($address)) {
                throw new UnsafeRemoteAddress('The remote host resolves to a private or reserved address.');
            }
        }

        return $addresses[0];
    }

    private function isPublicAddress(string $address): bool
    {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        foreach (self::NON_PUBLIC_RANGES as $range) {
            if ($this->addressIsInRange($address, $range)) {
                return false;
            }
        }

        return true;
    }

    private function addressIsInRange(string $address, string $range): bool
    {
        [$network, $prefixLength] = explode('/', $range, 2);
        $addressBytes = inet_pton($address);
        $networkBytes = inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || \strlen($addressBytes) !== \strlen($networkBytes)) {
            return false;
        }

        $prefix        = (int)$prefixLength;
        $wholeBytes    = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if (substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xff << 8 - $remainingBits & 0xff;

        return (\ord($addressBytes[$wholeBytes]) & $mask) === (\ord($networkBytes[$wholeBytes]) & $mask);
    }
}
