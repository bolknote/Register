<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Domain;

/** A normalized HTTPS origin without path, credentials, query, or fragment. */
final readonly class CanonicalOrigin
{
    public string $value;

    public string $host;

    public ?int $port;

    public function __construct(string $value)
    {
        $parts = parse_url(trim($value));
        if (!\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !\in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw new \InvalidArgumentException('The ActivityPub canonical origin must be an HTTPS origin without a path, credentials, query, or fragment.');
        }

        $host = strtolower($parts['host']);
        $invalidLabel = false;
        foreach (explode('.', $host) as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                $invalidLabel = true;
                break;
            }
        }

        if (\strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false || $invalidLabel) {
            throw new \InvalidArgumentException('The ActivityPub canonical host must be an ASCII DNS name. Configure an IDN in its A-label form.');
        }

        $port = $parts['port'] ?? null;
        if ($port === 443) {
            $port = null;
        }

        $this->host  = $host;
        $this->port  = $port;
        $this->value = 'https://' . $host . ($port === null ? '' : ':' . $port);
    }

    public function authority(): string
    {
        return $this->host . ($this->port === null ? '' : ':' . $this->port);
    }
}
