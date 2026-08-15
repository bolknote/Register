<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Morphology;

/** Converts unambiguous Church Slavonic glyphs to modern Russian search spellings. */
final class ChurchSlavonicNormalizer
{
    private const array LETTER_REPLACEMENTS = [
        'Ѕ' => 'З',
        'ѕ' => 'з',
        'Ꙁ' => 'З',
        'ꙁ' => 'з',
        'Ꙃ' => 'З',
        'ꙃ' => 'з',
        'Ꙅ' => 'З',
        'ꙅ' => 'з',
        'Ꙇ' => 'И',
        'ꙇ' => 'и',
        'Ѡ' => 'О',
        'ѡ' => 'о',
        'Ѻ' => 'О',
        'ѻ' => 'о',
        'Ѽ' => 'О',
        'ѽ' => 'о',
        'Ꙍ' => 'О',
        'ꙍ' => 'о',
        'Ꙩ' => 'О',
        'ꙩ' => 'о',
        'ᲂ' => 'о',
        'Ѥ' => 'Е',
        'ѥ' => 'е',
        'Ꙓ' => 'Е',
        'ꙓ' => 'е',
        'ᲇ' => 'е',
        'Ѧ' => 'Я',
        'ѧ' => 'я',
        'Ѩ' => 'Я',
        'ѩ' => 'я',
        'Ꙗ' => 'Я',
        'ꙗ' => 'я',
        'Ꙙ' => 'Я',
        'ꙙ' => 'я',
        'Ꙝ' => 'Я',
        'ꙝ' => 'я',
        'Ѫ' => 'У',
        'ѫ' => 'у',
        'Ѭ' => 'Ю',
        'ѭ' => 'ю',
        'Ꙕ' => 'Ю',
        'ꙕ' => 'ю',
        'Ѯ' => 'Кс',
        'ѯ' => 'кс',
        'Ѱ' => 'Пс',
        'ѱ' => 'пс',
        'Ѹ' => 'У',
        'ѹ' => 'у',
        'Ꙋ' => 'У',
        'ꙋ' => 'у',
        'ᲈ' => 'у',
        'Ѿ' => 'От',
        'ѿ' => 'от',
        'Ꙑ' => 'Ы',
        'ꙑ' => 'ы',
        'ᲆ' => 'ъ',
    ];

    /**
     * Stress, breathing and titlo-related marks used with Cyrillic text.
     * Diaeresis and breve are deliberately absent: they distinguish modern «ё» and «й».
     */
    private const string DIACRITICS_PATTERN = '/[\x{0300}\x{0301}\x{0311}\x{033E}\x{0483}-\x{0489}\x{2DE0}-\x{2DFF}\x{A66F}-\x{A672}\x{A674}-\x{A67D}\x{A69E}\x{A69F}\x{FE2E}\x{FE2F}]/u';

    public function normalize(string $word): string
    {
        // Keep canonically equivalent modern Russian spellings intact before removing accents.
        $word = strtr($word, [
            "Е\u{0308}" => 'Ё',
            "е\u{0308}" => 'ё',
            "И\u{0306}" => 'Й',
            "и\u{0306}" => 'й',
        ]);

        // In Russian Church Slavonic, a little yus after a sibilant corresponds to «а».
        $word = preg_replace_callback(
            '/(?<=[ЖЧШЩЦжчшщц])[ѦѧꙘꙙ]/u',
            static fn(array $matches): string => $matches[0] === mb_strtoupper($matches[0]) ? 'А' : 'а',
            $word,
        ) ?? $word;

        $word = strtr($word, self::LETTER_REPLACEMENTS);

        return preg_replace_callback(
            '/[\p{L}\p{M}]+/u',
            static function (array $matches): string {
                $token     = $matches[0];
                $baseToken = preg_replace('/\p{M}+/u', '', $token) ?? $token;
                if (preg_match('/\p{Cyrillic}/u', $baseToken) !== 1) {
                    return $token;
                }

                return preg_replace(self::DIACRITICS_PATTERN, '', $token) ?? $token;
            },
            $word,
        ) ?? $word;
    }
}
