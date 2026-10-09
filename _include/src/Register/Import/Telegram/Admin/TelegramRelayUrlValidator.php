<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram\Admin;

use Register\AdminYard\Validator\ValidatorInterface;
use Register\Import\Telegram\TelegramRelayConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TelegramRelayUrlValidator implements ValidatorInterface
{
    /** @return list<string> */
    #[\Override]
    public function getValidationErrors(mixed $value, TranslatorInterface $translator): array
    {
        return \is_string($value) && TelegramRelayConfig::validUrl($value)
            ? [] : [$translator->trans('Invalid Telegram relay URL.')];
    }
}
