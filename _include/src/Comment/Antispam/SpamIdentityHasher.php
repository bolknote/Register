<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use S2\Cms\Config\StringProxy;

final readonly class SpamIdentityHasher
{
    public function __construct(private StringProxy|string $secret)
    {
    }

    public function email(string $email): string
    {
        return $this->hash('email', mb_strtolower(trim($email)));
    }

    public function ip(string $ip): string
    {
        $normalized = trim($ip);
        $packed     = filter_var($normalized, FILTER_VALIDATE_IP) === false ? false : inet_pton($normalized);
        $value      = $packed === false ? $normalized : bin2hex($packed);

        return $this->hash('ip', $value);
    }

    public function text(string $text): string
    {
        $normalized = preg_replace('#\s+#u', ' ', mb_strtolower(trim($text)));

        return $this->hash('text', $normalized ?? mb_strtolower(trim($text)));
    }

    public function domain(string $domain): string
    {
        return $this->hash('domain', trim(mb_strtolower($domain), '.'));
    }

    public function visitor(string $visitorId): string
    {
        return $this->hash('visitor', $visitorId);
    }

    public function rateBucket(string $type, string $value): string
    {
        return $this->hash('rate:' . $type, $value);
    }

    public function nonce(string $nonce): string
    {
        return $this->hash('nonce', $nonce);
    }

    public function sign(string $purpose, string $payload): string
    {
        return hash_hmac('sha256', $purpose . "\0" . $payload, $this->getSecret());
    }

    private function hash(string $type, string $value): string
    {
        return hash_hmac('sha256', $type . "\0" . $value, $this->getSecret());
    }

    private function getSecret(): string
    {
        $secret = $this->secret instanceof StringProxy ? $this->secret->get() : $this->secret;
        if (\strlen($secret) < 32) {
            throw new \LogicException('The antispam secret must contain at least 32 bytes.');
        }

        return $secret;
    }
}
