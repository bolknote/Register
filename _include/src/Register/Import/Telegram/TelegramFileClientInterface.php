<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

interface TelegramFileClientInterface
{
    /** @param array<string, mixed> $media */
    public function download(array $media): ?string;
}
