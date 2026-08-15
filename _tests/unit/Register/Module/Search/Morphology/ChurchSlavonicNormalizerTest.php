<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Morphology;

use Codeception\Test\Unit;
use Register\Module\Search\Morphology\ChurchSlavonicNormalizer;

final class ChurchSlavonicNormalizerTest extends Unit
{
    /** @dataProvider spellingProvider */
    public function testNormalizesUnambiguousLettersAndMarks(string $historical, string $modern): void
    {
        self::assertSame($modern, (new ChurchSlavonicNormalizer())->normalize($historical));
    }

    /** @return iterable<string, array{string, string}> */
    public static function spellingProvider(): iterable
    {
        yield 'omega' => ['Ѡтрокъ', 'Отрокъ'];
        yield 'ksi' => ['ѯенія', 'ксенія'];
        yield 'psi' => ['Ѱаломъ', 'Псаломъ'];
        yield 'ot ligature' => ['ѿрокъ', 'отрокъ'];
        yield 'little yus' => ['ѧзыкъ', 'языкъ'];
        yield 'little yus after sibilant' => ['чѧдо', 'чадо'];
        yield 'uppercase little yus after sibilant' => ['ЧѦДО', 'ЧАДО'];
        yield 'big yus' => ['ѫгль', 'угль'];
        yield 'iotified big yus' => ['ѭность', 'юность'];
        yield 'uk' => ['ѹчитель', 'учитель'];
        yield 'dze' => ['ѕвѣзда', 'звѣзда'];
        yield 'yeru with back yer' => ['мꙑсль', 'мысль'];
        yield 'stress' => ["сло\u{0301}во", 'слово'];
        yield 'breathing and titlo' => ["а\u{0486}зъ бо\u{0483}гъ", 'азъ богъ'];
        yield 'titlo abbreviation is not guessed' => ["бг\u{0483}ъ", 'бгъ'];
        yield 'decomposed modern yo is preserved' => ["е\u{0308}лка", 'ёлка'];
        yield 'decomposed modern short i is preserved' => ["и\u{0306}од", 'йод'];
        yield 'Latin accent is untouched' => ["cafe\u{0301}", "cafe\u{0301}"];
    }
}
