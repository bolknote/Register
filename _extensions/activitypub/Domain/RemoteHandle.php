<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

/** A bounded, ASCII ActivityPub account handle suitable for WebFinger discovery. */
final readonly class RemoteHandle implements \Stringable
{
    public string $username;

    public string $domain;

    public function __construct(string $value)
    {
        $value = trim($value);
        if (str_starts_with($value, '@')) {
            $value = substr($value, 1);
        }

        if (\strlen($value) > 320
            || preg_match('/^([A-Za-z0-9_][A-Za-z0-9_.-]{0,63})@([A-Za-z0-9.-]{1,253})$/D', $value, $matches) !== 1
        ) {
            throw new \InvalidArgumentException('Enter a bounded ActivityPub handle in the form @user@example.org.');
        }

        $domain = strtolower($matches[2]);
        if (filter_var($domain, FILTER_VALIDATE_IP) !== false || !str_contains($domain, '.')) {
            throw new \InvalidArgumentException('An ActivityPub handle must use a public DNS domain name.');
        }

        foreach (explode('.', $domain) as $label) {
            if ($label === ''
                || \strlen($label) > 63
                || $label[0] === '-'
                || str_ends_with($label, '-')
                || preg_match('/^[a-z0-9-]+$/D', $label) !== 1
            ) {
                throw new \InvalidArgumentException('The ActivityPub handle domain is invalid; use its ASCII A-label form.');
            }
        }

        $this->username = $matches[1];
        $this->domain   = $domain;
    }

    public function accountUri(): string
    {
        return 'acct:' . $this->username . '@' . $this->domain;
    }

    public function webFingerUrl(): string
    {
        return 'https://' . $this->domain . '/.well-known/webfinger?resource=' . rawurlencode($this->accountUri());
    }

    #[\Override]
    public function __toString(): string
    {
        return '@' . $this->username . '@' . $this->domain;
    }
}
