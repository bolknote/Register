<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final class NativeHostResolver implements HostResolverInterface
{
    /** @return list<string> */
    #[\Override]
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        $records   = s2_call_without_warnings(static fn(): array|false => dns_get_record($host, DNS_A | DNS_AAAA));
        if (\is_array($records)) {
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $field) {
                    $address = $record[$field] ?? null;
                    if (\is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                        $addresses[$address] = $address;
                    }
                }
            }
        }

        if ($addresses === []) {
            $ipv4 = s2_call_without_warnings(static fn(): array|false => gethostbynamel($host));
            if (\is_array($ipv4)) {
                foreach ($ipv4 as $address) {
                    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                        $addresses[$address] = $address;
                    }
                }
            }
        }

        return array_values($addresses);
    }
}
