<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

use voku\helper\ASCII;

final readonly class PortableAsciiTransliterator implements TransliteratorInterface
{
    #[\Override]
    public function transliterate(string $text): string
    {
        return ASCII::to_ascii(
            $text,
            ASCII::RUSSIAN_LANGUAGE_CODE,
            remove_unsupported_chars: true,
            use_transliterate: false,
        );
    }
}
