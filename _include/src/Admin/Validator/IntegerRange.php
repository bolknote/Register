<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Validator;

use S2\AdminYard\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IntegerRange implements ValidatorInterface
{
    public function __construct(private int $min, private int $max)
    {
        if ($min > $max) {
            throw new \InvalidArgumentException('Minimum value cannot be greater than maximum value.');
        }
    }

    /** @return list<string> */
    #[\Override]
    public function getValidationErrors(mixed $value, TranslatorInterface $translator): array
    {
        $validInteger = \is_int($value)
            || (\is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1);
        if (!$validInteger || (int)$value < $this->min || (int)$value > $this->max) {
            return [$translator->trans(
                'This value must be between {{ min }} and {{ max }}.',
                ['{{ min }}' => $this->min, '{{ max }}' => $this->max],
            )];
        }

        return [];
    }
}
