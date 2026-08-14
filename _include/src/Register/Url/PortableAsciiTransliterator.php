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
    /**
     * URL-oriented ASCII equivalents for Slavic Cyrillic letters which are not
     * handled consistently by ICU and portable-ascii.
     *
     * @var array<string, string>
     */
    private const array SLAVIC_CYRILLIC = [
        'Ѐ' => 'E',  'ѐ' => 'e',
        'Ѝ' => 'I',  'ѝ' => 'i',
        'Ў' => 'U',  'ў' => 'u',
        'Ҍ' => '',   'ҍ' => '',
        'Ѣ' => 'E',  'ѣ' => 'e',
        'І' => 'I',  'і' => 'i',
        'Ѳ' => 'F',  'ѳ' => 'f',
        'Ѵ' => 'I',  'ѵ' => 'i',
        'Ѕ' => 'Dz', 'ѕ' => 'dz',
        'Ѥ' => 'Ye', 'ѥ' => 'ye',
        'Ѧ' => 'Ya', 'ѧ' => 'ya',
        'Ѩ' => 'Ya', 'ѩ' => 'ya',
        'Ѫ' => 'U',  'ѫ' => 'u',
        'Ѭ' => 'Yu', 'ѭ' => 'yu',
        'Ѯ' => 'Ks', 'ѯ' => 'ks',
        'Ѱ' => 'Ps', 'ѱ' => 'ps',
        'Ѡ' => 'O',  'ѡ' => 'o',
        'Ѿ' => 'Ot', 'ѿ' => 'ot',
        'Ѹ' => 'U',  'ѹ' => 'u',
        'Ꙁ' => 'Z',  'ꙁ' => 'z',
        'Ꙃ' => 'Dz', 'ꙃ' => 'dz',
        'Ꙅ' => 'Dz', 'ꙅ' => 'dz',
        'Ꙇ' => 'I',  'ꙇ' => 'i',
        'Ꙉ' => 'Dj', 'ꙉ' => 'dj',
        'Ꙋ' => 'U',  'ꙋ' => 'u',
        'Ꙍ' => 'O',  'ꙍ' => 'o',
        'Ꙏ' => '',   'ꙏ' => '',
        'Ꙑ' => 'Y',  'ꙑ' => 'y',
        'Ꙓ' => 'Ye', 'ꙓ' => 'ye',
        'Ꙕ' => 'Yu', 'ꙕ' => 'yu',
        'Ꙗ' => 'Ya', 'ꙗ' => 'ya',
        'Ꙙ' => 'Ya', 'ꙙ' => 'ya',
        'Ꙛ' => 'Ya', 'ꙛ' => 'ya',
        'Ꙝ' => 'Ya', 'ꙝ' => 'ya',
        'Ꙡ' => 'Ts', 'ꙡ' => 'ts',
        'Ꙣ' => 'D',  'ꙣ' => 'd',
        'Ꙥ' => 'L',  'ꙥ' => 'l',
        'Ꙧ' => 'M',  'ꙧ' => 'm',
        'Ꙩ' => 'O',  'ꙩ' => 'o',
        'Ꙫ' => 'O',  'ꙫ' => 'o',
        'Ꙭ' => 'O',  'ꙭ' => 'o',
        'ꙮ' => 'O',
        'Ꚙ' => 'O',  'ꚙ' => 'o',
        'Ꚛ' => 'O',  'ꚛ' => 'o',
        'ᲀ' => 'V',  'ᲁ' => 'D',
        'ᲂ' => 'O',  'ᲃ' => 'S',
        'ᲄ' => 'T',  'ᲅ' => 'T',
        'ᲆ' => '',   'ᲇ' => 'E',
        'ᲈ' => 'U',
    ];

    #[\Override]
    public function transliterate(string $text): string
    {
        return ASCII::to_ascii(
            strtr($text, self::SLAVIC_CYRILLIC),
            ASCII::RUSSIAN_LANGUAGE_CODE,
            remove_unsupported_chars: true,
            use_transliterate: false,
        );
    }
}
