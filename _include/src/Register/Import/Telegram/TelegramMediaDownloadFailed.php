<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

/** A snapshot can be retried after its uploaded bytes become available. */
final class TelegramMediaDownloadFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Telegram media upload is incomplete; the event can be retried.');
    }
}
