<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\AdminYard\Form;

use S2\AdminYard\Form\Autocomplete;
use S2\AdminYard\Form\FormFactory;
use S2\AdminYard\Validator\Choice;

final class CustomAutocomplete extends Autocomplete
{
    private ?string $entityName = null;
    private ?string $hash = null;
    private ?\Closure $optionsProvider = null;

    /** @var list<array{value: int|string, text: string}>|null */
    private ?array $options = null;

    private bool $allowEmpty = false;

    #[\Override]
    public function getHtml(?string $id = null): string
    {
        if ($this->entityName === null) {
            throw new \LogicException('Entity name must be set before using autocomplete.');
        }

        $availableOptions = $this->options ?? $this->fillOptions();

        $selectId  = $id ?? uniqid('autocomplete-', true);
        $controlId = $selectId . '-control';
        $emptyLabel = FormFactory::EMPTY_SELECT_LABEL;
        $options = '';
        $currentOption = $emptyLabel;
        foreach ($availableOptions as $option) {
            $key = (string)$option['value'];
            $options .= '<option value="' . self::escape($key) . '" ' . ($key === $this->value ? 'selected' : '') . '>'
                . self::escape($option['text'])
                . '</option>';
            if ($key === $this->value) {
                $currentOption = $option['text'];
            }
        }

        $fetchUrl = '?' . http_build_query([
            'entity' => $this->entityName,
            'hash'   => $this->hash,
            'action' => 'autocomplete',
        ]);

        return \sprintf(
            '<div class="ay-select" id="%s" data-autocomplete-control data-allow-empty="%d" data-empty-label="%s" data-fetch-url="%s">'
            . '<button type="button" class="ay-select-button">%s</button>'
            . '<div class="ay-select-dropdown" style="display: none;">'
            . '<div class="search"><span class="highlight"></span></div>'
            . '<select name="%s" id="%s" size="5" class="dropdown-select">%s</select>'
            . '</div></div>',
            self::escape($controlId),
            (int)$this->allowEmpty,
            self::escape($emptyLabel),
            self::escape($fetchUrl),
            self::escape($currentOption),
            self::escape($this->fieldName),
            self::escape($selectId),
            $options,
        );
    }

    #[\Override]
    public function setValue($value): static
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('Value must be a string, "%s" given.', \gettype($value)));
        }
        $this->value = $value;
        $this->fillOptions();

        return $this;
    }

    #[\Override]
    public function setAutocompleteParams(
        string   $entityName,
        string   $hash,
        \Closure $optionsProvider,
        bool     $allowEmpty,
    ): static {
        $this->entityName      = $entityName;
        $this->hash            = $hash;
        $this->optionsProvider = $optionsProvider;
        $this->allowEmpty      = $allowEmpty;

        return $this;
    }

    /** @return list<array{value: int|string, text: string}> */
    private function fillOptions(): array
    {
        $this->options = [
            ...$this->allowEmpty ? [['value' => '', 'text' => FormFactory::EMPTY_SELECT_LABEL]] : [],
            ...$this->provideOptions(),
        ];

        return $this->options;
    }

    /** @return list<array{value: int|string, text: string}> */
    private function provideOptions(?int $limit = null): array
    {
        if (!$this->optionsProvider instanceof \Closure) {
            throw new \LogicException('Options provider must be set before using autocomplete.');
        }

        $providedOptions = $limit === null
            ? ($this->optionsProvider)($this->value)
            : ($this->optionsProvider)($this->value, $limit);
        if (!\is_array($providedOptions)) {
            throw new \UnexpectedValueException('Options provider must return an array.');
        }

        $options = [];
        foreach ($providedOptions as $option) {
            if (!\is_array($option) || !\array_key_exists('value', $option) || !\array_key_exists('text', $option)) {
                throw new \UnexpectedValueException('Every autocomplete option must have value and text fields.');
            }

            $value = $option['value'];
            $text  = $option['text'];
            if ((!\is_int($value) && !\is_string($value)) || !\is_string($text)) {
                throw new \UnexpectedValueException(
                    'Autocomplete option value must be an integer or string and text must be a string.',
                );
            }

            $options[] = ['value' => $value, 'text' => $text];
        }

        return $options;
    }

    /** @return list<Choice> */
    #[\Override]
    protected function getInternalValidators(): array
    {
        $options = [
            ...$this->allowEmpty ? [['value' => '', 'text' => FormFactory::EMPTY_SELECT_LABEL]] : [],
            ...$this->provideOptions(0),
        ];

        return [new Choice(array_map(static fn(array $option): string => (string)$option['value'], $options), true)];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
