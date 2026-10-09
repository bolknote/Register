<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram\Admin;

use Register\AdminYard\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TelegramIdValidator implements ValidatorInterface
{
    public function __construct(private bool $chat)
    {
    }

    /** @return list<string> */
    #[\Override]
    public function getValidationErrors(mixed $value, TranslatorInterface $translator): array
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id !== false && ($id === 0 || ($this->chat
            ? $id < -1_000_000_000_000 && $id >= -9_007_199_254_740_991
            : $id > 0 && $id <= 9_007_199_254_740_991
        ))) {
            return [];
        }

        return [$translator->trans($this->chat ? 'Invalid Telegram chat ID.' : 'Invalid Telegram user ID.')];
    }
}
