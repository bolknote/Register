<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

/** Optional authenticated HTTPS transport for hosts without a direct Telegram route. */
final readonly class TelegramRelayConfig
{
    public function __construct(public string $url = '', public string $token = '')
    {
    }

    public static function validUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }

        if (\strlen($url) > 512 || preg_match('~^https://[A-Za-z0-9.-]+(?::[0-9]+)?(?:/[A-Za-z0-9._\~-]+)*/?$~D', $url) !== 1) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function enabled(): bool
    {
        return $this->url !== '' && self::validUrl($this->url) && preg_match('/^[a-f0-9]{64}$/D', $this->token) === 1;
    }

    public function apiBaseUrl(): string
    {
        return $this->enabled() ? rtrim($this->url, '/') . '/api' : 'https://api.telegram.org';
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->enabled() ? ['X-Register-Telegram-Relay-Key' => $this->token] : [];
    }
}
