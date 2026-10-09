<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Register\Core\Config\DynamicConfigProvider;

final readonly class TelegramSettings
{
    public const string ENABLED = 'REGISTER_TELEGRAM_IMPORT_ENABLED';

    public const string BOT_TOKEN = 'REGISTER_TELEGRAM_BOT_TOKEN';

    public const string BRIDGE_TOKEN = 'REGISTER_TELEGRAM_BRIDGE_TOKEN';

    public const string CHANNEL_ID = 'REGISTER_TELEGRAM_CHANNEL_ID';

    public const string DISCUSSION_ID = 'REGISTER_TELEGRAM_DISCUSSION_ID';

    public const string OWNER_TELEGRAM_ID = 'REGISTER_TELEGRAM_OWNER_ID';

    public const string AUTHOR_ID = 'REGISTER_TELEGRAM_AUTHOR_ID';

    public const string RELAY_URL = 'REGISTER_TELEGRAM_RELAY_URL';

    public const string RELAY_TOKEN = 'REGISTER_TELEGRAM_RELAY_TOKEN';

    public const array DEFAULTS = [
        self::ENABLED => '0',
        self::BOT_TOKEN => '',
        self::BRIDGE_TOKEN => '',
        self::CHANNEL_ID => '0',
        self::DISCUSSION_ID => '0',
        self::OWNER_TELEGRAM_ID => '0',
        self::AUTHOR_ID => '0',
        self::RELAY_URL => '',
        self::RELAY_TOKEN => '',
    ];

    public function __construct(private DynamicConfigProvider $provider)
    {
    }

    public function liveConfig(): TelegramLiveImportConfig
    {
        return TelegramLiveImportConfig::fromArray([
            'enabled' => $this->provider->get(self::ENABLED) === '1',
            'token' => $this->provider->get(self::BRIDGE_TOKEN),
            'channel_chat_id' => $this->provider->get(self::CHANNEL_ID),
            'discussion_chat_id' => $this->provider->get(self::DISCUSSION_ID),
            'owner_telegram_user_id' => $this->provider->get(self::OWNER_TELEGRAM_ID),
            'author_user_id' => $this->provider->get(self::AUTHOR_ID),
        ]);
    }

    public function botToken(): string
    {
        return trim((string)$this->provider->get(self::BOT_TOKEN));
    }

    public function relay(): TelegramRelayConfig
    {
        return new TelegramRelayConfig(trim((string)$this->provider->get(self::RELAY_URL)), trim((string)$this->provider->get(self::RELAY_TOKEN)));
    }

    /** Preserve an existing file-based bridge when upgrading to editable settings.
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public static function legacyDefaults(array $config): array
    {
        $live = TelegramLiveImportConfig::fromArray($config);

        return [
            self::ENABLED => $live->enabled() ? '1' : '0',
            self::BOT_TOKEN => \is_string($config['bot_token'] ?? null) ? $config['bot_token'] : '',
            self::BRIDGE_TOKEN => $live->token,
            self::CHANNEL_ID => (string)$live->channelChatId,
            self::DISCUSSION_ID => (string)$live->discussionChatId,
            self::OWNER_TELEGRAM_ID => (string)$live->ownerTelegramUserId,
            self::AUTHOR_ID => (string)$live->authorUserId,
        ];
    }
}
