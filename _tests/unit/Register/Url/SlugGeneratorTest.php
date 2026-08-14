<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Url;

use Codeception\Test\Unit;
use Register\Url\PortableAsciiTransliterator;
use Register\Url\SlugGenerator;
use Register\Url\TransliteratorInterface;

final class SlugGeneratorTest extends Unit
{
    public function testUsesPrimaryTransliteratorWhenItProducesAscii(): void
    {
        $primary  = new RecordingTransliterator('Privet, mir!');
        $fallback = new RecordingTransliterator('fallback-must-not-run');

        $slug = (new SlugGenerator($fallback, $primary))->generate('Привет, мир!');

        self::assertSame('privet-mir', $slug);
        self::assertSame(1, $primary->calls);
        self::assertSame(0, $fallback->calls);
    }

    public function testFallsBackWhenIcuLeavesUnsupportedCharacters(): void
    {
        $primary  = new RecordingTransliterator('💥 !!!');
        $fallback = new RecordingTransliterator('explosion');

        $slug = (new SlugGenerator($fallback, $primary))->generate('💥 !!!');

        self::assertSame('explosion', $slug);
        self::assertSame(1, $fallback->calls);
    }

    public function testFallsBackWhenPrimaryReturnsInvalidUtf8(): void
    {
        $primary  = new RecordingTransliterator("\xff");
        $fallback = new RecordingTransliterator('safe-title');

        $slug = (new SlugGenerator($fallback, $primary))->generate('Safe title');

        self::assertSame('safe-title', $slug);
        self::assertSame(1, $fallback->calls);
    }

    /** @dataProvider portableFallbackProvider */
    public function testPortableFallback(string $title, string $expected): void
    {
        $generator = new SlugGenerator(new PortableAsciiTransliterator());

        self::assertSame($expected, $generator->generate($title));
    }

    public function testTruncatesSlugToDatabaseColumnLength(): void
    {
        $generator = new SlugGenerator(new RecordingTransliterator(str_repeat('word-', 60)));

        $slug = $generator->generate('Long title');

        self::assertLessThanOrEqual(SlugGenerator::MAX_LENGTH, strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
    }

    public static function portableFallbackProvider(): \Iterator
    {
        yield 'russian' => ['Привет, мир!', 'privet-mir'];
        yield 'russian letters' => ['Ёжик в тумане', 'yozhik-v-tumane'];
        yield 'pre-reform Russian' => [
            'Міръ и вѣра: Ѳеодоръ и мѵро',
            'mir-i-vera-feodor-i-miro',
        ];
        yield 'Old Church Slavonic letters' => [
            'Ѹ Ꙑ Ѧ Ѫ Ѭ Ѯ Ѱ Ѡ Ѿ Ꙁ ꙃ ꙇ ꙉ ꙍ ꙓ ꙕ ꙗ',
            'u-y-ya-u-yu-ks-ps-o-ot-z-dz-i-dj-o-ye-yu-ya',
        ];
        yield 'Belarusian and Bulgarian letters' => [
            'Ў нескладовае; Ѐ и Ѝ',
            'u-neskladovae-e-i-i',
        ];
        yield 'historic Cyrillic glyph variants' => [
            'ꙑ ѹ ꙙ ꙝ ꙡ ꙣ ꙥ ꙧ ꙩ ꙫ ꙭ ꙮ ꚙ ꚛ ᲀᲁᲂᲃᲄᲅᲆᲇᲈ',
            'y-u-ya-ya-ts-d-l-m-o-o-o-o-o-o-vdostteu',
        ];
        yield 'latin diacritics' => ['Crème brûlée', 'creme-brulee'];
        yield 'normalization' => ['  Repeated___separators -- ', 'repeated-separators'];
        yield 'empty input' => ['', ''];
        yield 'unsupported symbols' => ['💥 !!!', ''];
    }
}

final class RecordingTransliterator implements TransliteratorInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $result)
    {
    }

    #[\Override]
    public function transliterate(string $text): string
    {
        unset($text);
        ++$this->calls;

        return $this->result;
    }
}
