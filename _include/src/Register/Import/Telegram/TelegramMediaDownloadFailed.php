<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

/** Deliberately excludes Bot API URLs, credentials and remote error bodies. */
final class TelegramMediaDownloadFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Telegram media download failed; the event can be retried.');
    }
}
