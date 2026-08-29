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
    public function __construct(
        private MailSettings               $settings,
        private ?DnsTxtLookupInterface     $txtLookup = null,
    ) {
    }

    /** @return array{available:bool,domain:string,spf:bool|null,dmarc:bool|null,dkim:bool|null,dkim_name:string|null} */
    public function inspect(): array
    {
        $domain = $this->settings->dkimDomain();
        if ($domain === '') {
            return [
                'available' => false,
                'domain'    => $domain,
                'spf'       => null,
                'dmarc'     => null,
                'dkim'      => null,
                'dkim_name' => null,
            ];
        }

        $selector = $this->settings->dkimSelector();
        $dkimName = $selector === '' ? null : $selector . '._domainkey.' . $domain;
        $names = [$domain, '_dmarc.' . $domain];
        if ($dkimName !== null) {
            $names[] = $dkimName;
        }

        $records = ($this->txtLookup ?? new BoundedDnsTxtLookup())->lookup($names);
        if ($records === null) {
            return [
                'available' => false,
                'domain'    => $domain,
                'spf'       => null,
                'dmarc'     => null,
                'dkim'      => null,
                'dkim_name' => $dkimName,
            ];
        }

        return [
            'available' => true,
            'domain'    => $domain,
            'spf'       => $this->hasTxt($records[$domain] ?? null, 'v=spf1'),
            'dmarc'     => $this->hasTxt($records['_dmarc.' . $domain] ?? null, 'v=dmarc1'),
            'dkim'      => $dkimName === null ? null : $this->hasTxt($records[$dkimName] ?? null, 'v=dkim1'),
            'dkim_name' => $dkimName,
        ];
    }

    /** @param list<string>|null $records */
    private function hasTxt(?array $records, string $prefix): ?bool
    {
        if ($records === null) {
            return null;
        }

        foreach ($records as $text) {
            if (str_starts_with(strtolower(trim($text)), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
