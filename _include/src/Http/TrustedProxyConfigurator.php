<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Http;

use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyConfigurator
{
    /** @param list<mixed> $trustedProxies */
    public static function configure(array $trustedProxies): void
    {
        $normalizedProxies = [];
        foreach ($trustedProxies as $trustedProxy) {
            $normalizedProxy = \is_string($trustedProxy) ? trim($trustedProxy) : null;
            if ($normalizedProxy === null || !self::isValidIpOrCidr($normalizedProxy)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Invalid trusted proxy IP or CIDR: "%s".',
                    \is_scalar($trustedProxy) ? (string)$trustedProxy : get_debug_type($trustedProxy),
                ));
            }

            $normalizedProxies[] = $normalizedProxy;
        }

        Request::setTrustedProxies($normalizedProxies, Request::HEADER_X_FORWARDED_FOR);
    }

    private static function isValidIpOrCidr(string $value): bool
    {
        $parts = explode('/', $value);
        if (\count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (!isset($parts[1])) {
            return true;
        }

        if ($parts[1] === '' || preg_match('/^[0-9]{1,3}$/D', $parts[1]) !== 1) {
            return false;
        }

        $maxPrefix = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return (int)$parts[1] <= $maxPrefix;
    }
}
