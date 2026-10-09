<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

/** Explicitly scoped credentials for the optional Serverless bridge. */
final readonly class TelegramLiveImportConfig
{
    public function __construct(
        public string $token = '',
        public int $discussionChatId = 0,
        public int $channelChatId = 0,
        public int $ownerTelegramUserId = 0,
        public int $authorUserId = 0,
        public bool $active = true,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        return new self(
            \is_string($config['token'] ?? null) ? $config['token'] : '',
            (int)filter_var($config['discussion_chat_id'] ?? 0, FILTER_VALIDATE_INT),
            (int)filter_var($config['channel_chat_id'] ?? 0, FILTER_VALIDATE_INT),
            (int)filter_var($config['owner_telegram_user_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            (int)filter_var($config['author_user_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            ($config['enabled'] ?? true) === true,
        );
    }

    public function enabled(): bool
    {
        return $this->active && preg_match('/^[a-f0-9]{64}$/D', $this->token) === 1
            && $this->discussionChatId < -1_000_000_000_000
            && $this->channelChatId < -1_000_000_000_000
            && $this->discussionChatId !== $this->channelChatId;
    }

    public function discussionExportId(): int
    {
        return -$this->discussionChatId - 1_000_000_000_000;
    }

    public function channelExportId(): string
    {
        return 'channel' . (-$this->channelChatId - 1_000_000_000_000);
    }
}
