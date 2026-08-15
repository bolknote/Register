<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth\Admin;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\Admin\TranslationProvider;
use S2\AdminYard\Translator;

final class TranslationProviderTest extends Unit
{
    public function testRussianLinkUsageCountsAreDeclinedIndependently(): void
    {
        $translator = $this->translator('ru');
        $cases      = [
            [1, 1, '1 раз в 1 материале'],
            [2, 1, '2 раза в 1 материале'],
            [5, 2, '5 раз в 2 материалах'],
            [11, 5, '11 раз в 5 материалах'],
            [21, 21, '21 раз в 21 материале'],
            [22, 22, '22 раза в 22 материалах'],
        ];

        foreach ($cases as [$occurrences, $materials, $expected]) {
            self::assertSame($expected, $this->usage($translator, $occurrences, $materials));
        }
    }

    public function testEnglishLinkUsageCountsAreDeclinedIndependently(): void
    {
        $translator = $this->translator('en');

        self::assertSame('1 occurrence in 1 document', $this->usage($translator, 1, 1));
        self::assertSame('2 occurrences in 1 document', $this->usage($translator, 2, 1));
        self::assertSame('1 occurrence in 2 documents', $this->usage($translator, 1, 2));
    }

    private function translator(string $locale): Translator
    {
        return new Translator(
            (new TranslationProvider())->getTranslations($locale, $locale),
            $locale,
        );
    }

    private function usage(Translator $translator, int $occurrences, int $materials): string
    {
        return $translator->trans('Link occurrence count', [
            '%count%' => $occurrences,
            '{{ count }}' => $occurrences,
        ]) . ' ' . $translator->trans('Link material count', [
            '%count%' => $materials,
            '{{ count }}' => $materials,
        ]);
    }
}
