<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

final readonly class IcuTransliterator implements TransliteratorInterface
{
    private const string RULES = 'Any-Latin; Latin-ASCII; Lower()';

    public function __construct(private \Transliterator $transliterator)
    {
    }

    public static function create(): ?self
    {
        if (!class_exists(\Transliterator::class, false)) {
            return null;
        }

        $transliterator = \Transliterator::create(self::RULES);

        return $transliterator === null ? null : new self($transliterator);
    }

    #[\Override]
    public function transliterate(string $text): string
    {
        $transliterated = $this->transliterator->transliterate($text);

        return $transliterated === false ? $text : $transliterated;
    }
}
