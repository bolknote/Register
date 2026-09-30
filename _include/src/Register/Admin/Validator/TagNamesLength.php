<?php

declare(strict_types = 1);

namespace Register\Admin\Validator;

use Register\AdminYard\Validator\Length;
use Register\AdminYard\Validator\ValidatorInterface;
use Register\Content\TagRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TagNamesLength implements ValidatorInterface
{
    /** @return list<string> */
    #[\Override]
    public function getValidationErrors(mixed $value, TranslatorInterface $translator): array
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Tag names must be a string.');
        }

        $length = new Optional(new Length(max: TagRepository::MAX_NAME_LENGTH));
        foreach (explode(',', $value) as $tag) {
            $errors = $length->getValidationErrors(trim($tag), $translator);
            if ($errors !== []) {
                return $errors;
            }
        }

        return [];
    }
}
