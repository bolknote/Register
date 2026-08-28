<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

/** Best-effort DNS visibility. A found record is not claimed to be a successful received-mail check. */
final readonly class MailDnsInspector
{
    public function __construct(private MailSettings $settings)
    {
    }

    /** @return array{available:bool,domain:string,spf:bool|null,dmarc:bool|null,dkim:bool|null,dkim_name:string|null} */
    public function inspect(): array
    {
        $domain = $this->settings->dkimDomain();
        if (!\function_exists('dns_get_record') || $domain === '') {
            return [
                'available' => \function_exists('dns_get_record'),
                'domain'    => $domain,
                'spf'       => null,
                'dmarc'     => null,
                'dkim'      => null,
                'dkim_name' => null,
            ];
        }

        $selector = $this->settings->dkimSelector();
        $dkimName = $selector === '' ? null : $selector . '._domainkey.' . $domain;

        return [
            'available' => true,
            'domain'    => $domain,
            'spf'       => $this->hasTxt($domain, 'v=spf1'),
            'dmarc'     => $this->hasTxt('_dmarc.' . $domain, 'v=dmarc1'),
            'dkim'      => $dkimName === null ? null : $this->hasTxt($dkimName, 'v=dkim1'),
            'dkim_name' => $dkimName,
        ];
    }

    private function hasTxt(string $name, string $prefix): ?bool
    {
        try {
            $records = register_call_without_warnings(static fn(): array|false => dns_get_record($name, DNS_TXT));
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($records)) {
            return null;
        }

        foreach ($records as $record) {
            $text = $record['txt'] ?? null;
            if (\is_string($text) && str_starts_with(strtolower(trim($text)), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
