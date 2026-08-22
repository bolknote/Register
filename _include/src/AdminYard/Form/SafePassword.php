<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\AdminYard\Form;

use S2\AdminYard\Form\Password;

/** Password control that never reflects submitted credentials back into HTML. */
final class SafePassword extends Password
{
    #[\Override]
    public function getHtml(?string $id = null): string
    {
        $autocomplete = match ($this->fieldName) {
            'current_password' => 'current-password',
            'password'         => 'new-password',
            // Browsers commonly ignore "off" on password controls and autofill the
            // administrator password into API-key fields as soon as they receive focus.
            default            => 'new-password',
        };
        $maximumLength = \in_array($this->fieldName, ['current_password', 'password'], true)
            ? ' maxlength="255"'
            : '';

        return sprintf(
            '<input type="password" name="%s" value="" autocomplete="%s"%s%s>',
            htmlspecialchars($this->fieldName, ENT_QUOTES, 'UTF-8'),
            $autocomplete,
            $maximumLength,
            $id !== null ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '',
        );
    }
}
