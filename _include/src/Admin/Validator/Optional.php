<?php

declare(strict_types = 1);

namespace S2\Cms\Admin\Validator;

use S2\AdminYard\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class Optional implements ValidatorInterface
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    /** @return list<string> */
    #[\Override]
    public function getValidationErrors(mixed $value, TranslatorInterface $translator): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $errors = [];
        foreach ($this->validator->getValidationErrors($value, $translator) as $error) {
            if (!\is_string($error)) {
                throw new \UnexpectedValueException('A validator error must be a string.');
            }

            $errors[] = $error;
        }

        return $errors;
    }
}
