<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Morphology;

use Codeception\Test\Unit;
use Register\Module\Search\Morphology\PreReformRussianNormalizer;

final class PreReformRussianNormalizerTest extends Unit
{
    /** @dataProvider spellingProvider */
    public function testNormalizesUnambiguousPreReformSpellings(string $oldSpelling, string $modernSpelling): void
    {
        self::assertSame($modernSpelling, (new PreReformRussianNormalizer())->normalize($oldSpelling));
    }

    /** @dataProvider endingProvider */
    public function testSuggestsModernEndingsForDictionaryValidation(string $oldSpelling, string $modernSpelling): void
    {
        self::assertContains($modernSpelling, (new PreReformRussianNormalizer())->modernAlternatives($oldSpelling));
    }

    /** @return iterable<string, array{string, string}> */
    public static function spellingProvider(): iterable
    {
        yield 'yat' => ['дѣти', 'дети'];
        yield 'decimal i' => ['Россія', 'Россия'];
        yield 'fita' => ['Ѳома', 'Фома'];
        yield 'izhitsa' => ['сѵнодъ', 'синод'];
        yield 'accented izhitsa' => ['мѷро', 'миро'];
        yield 'word-final hard sign' => ['міръ', 'мир'];
        yield 'compound boundary hard sign' => ['изъ-за', 'из-за'];
        yield 'uppercase' => ['СЪѢЗДЪ', 'СЪЕЗД'];
        yield 'modern separator' => ['подъезд', 'подъезд'];
        yield 'modern separator before vowel' => ['разъярённый', 'разъярённый'];
        yield 'standalone hard sign' => ['ъ', 'ъ'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function endingProvider(): iterable
    {
        yield 'adjective ending' => ['новаго', 'нового'];
        yield 'ending after a sibilant' => ['хорошаго', 'хорошего'];
        yield 'soft adjective ending' => ['дальняго', 'дальнего'];
        yield 'feminine plural hard ending' => ['новыя', 'новые'];
        yield 'feminine plural soft ending' => ['хорошія', 'хорошие'];
        yield 'feminine pronoun' => ['онѣ', 'они'];
        yield 'feminine pronoun case form' => ['однѣхъ', 'одних'];
    }
}
