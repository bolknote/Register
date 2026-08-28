<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Validator;

use Register\AdminYard\Validator\ValidatorInterface;
use Register\Core\Model\PasswordPolicy;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SecurePassword implements ValidatorInterface
{
    /** @return list<string> */
    #[\Override]
    public function getValidationErrors(mixed $value, TranslatorInterface $translator): array
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('SecurePassword can validate only strings.');
        }

        $errors = [];
        foreach (PasswordPolicy::violations($value) as $violation) {
            $errors[] = match ($violation) {
                'too_short' => $translator->trans(
                    'The password must contain at least {{ limit }} characters.',
                    ['{{ limit }}' => PasswordPolicy::MIN_LENGTH],
                ),
                'too_long' => $translator->trans(
                    'The password must contain at most {{ limit }} characters.',
                    ['{{ limit }}' => PasswordPolicy::MAX_LENGTH],
                ),
                'common' => $translator->trans('Choose a less common password.'),
                'contains_login' => $translator->trans('The password must not contain the login.'),
            };
        }

        return $errors;
    }
}
