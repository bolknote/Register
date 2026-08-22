<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\AdminYard\Form;

use Register\AdminYard\Form\FormControlFactory;
use Register\AdminYard\Form\FormControlInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CustomFormControlFactory extends FormControlFactory
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[\Override]
    public function create(string $control, string $fieldName): FormControlInterface
    {
        if ($control === 'password') {
            return new SafePassword($fieldName);
        }

        if ($control === 'datetime') {
            return new CustomDateTime($fieldName, $this->translator);
        }

        if ($control === 'html_textarea') {
            return new HtmlTextarea($fieldName);
        }

        if ($control === 'autocomplete') {
            return new CustomAutocomplete($fieldName);
        }

        return parent::create($control, $fieldName);
    }
}
