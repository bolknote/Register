<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Core\Config\DynamicConfigProvider;

/** Runtime configuration for public sign-in methods. */
final readonly class PublicAuthSettings
{
    public const string EMAIL_ENABLED_CONFIG_KEY = 'REGISTER_AUTH_EMAIL_ENABLED';

    public const string VK_CLIENT_ID_CONFIG_KEY = 'REGISTER_AUTH_VK_CLIENT_ID';

    public const string YANDEX_CLIENT_ID_CONFIG_KEY = 'REGISTER_AUTH_YANDEX_CLIENT_ID';

    public const string YANDEX_CLIENT_SECRET_CONFIG_KEY = 'REGISTER_AUTH_YANDEX_CLIENT_SECRET';

    public function __construct(private DynamicConfigProvider $configProvider)
    {
    }

    public function emailEnabled(): bool
    {
        return $this->configProvider->getBoolProxy(self::EMAIL_ENABLED_CONFIG_KEY)->get();
    }

    public function vkClientId(): string
    {
        return trim($this->configProvider->getStringProxy(self::VK_CLIENT_ID_CONFIG_KEY)->get());
    }

    public function yandexClientId(): string
    {
        return trim($this->configProvider->getStringProxy(self::YANDEX_CLIENT_ID_CONFIG_KEY)->get());
    }

    public function yandexClientSecret(): string
    {
        return trim($this->configProvider->getStringProxy(self::YANDEX_CLIENT_SECRET_CONFIG_KEY)->get());
    }

    public function vkEnabled(): bool
    {
        return $this->vkClientId() !== '';
    }

    public function yandexEnabled(): bool
    {
        return $this->yandexClientId() !== '' && $this->yandexClientSecret() !== '';
    }

    public function hasExternalMethods(): bool
    {
        return $this->emailEnabled() || $this->vkEnabled() || $this->yandexEnabled();
    }
}
