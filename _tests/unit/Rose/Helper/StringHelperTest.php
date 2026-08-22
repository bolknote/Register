<?php
/**
 * @copyright 2023-2024 Roman Parpalak
 * @license   MIT
 */

declare(strict_types = 1);

namespace Register\Rose\Test\Helper;

use Codeception\Test\Unit;
use Register\Rose\Helper\StringHelper;

/**
 * @group string
 */
final class StringHelperTest extends Unit
{
    /**
     * @dataProvider sentenceDataProvider
     * @param list<string> $sentences
     */
    public function testSentences(string $text, array $sentences, bool $hasFormatting = false): void
    {
        foreach (StringHelper::sentencesFromText($text, $hasFormatting) as $i => $str) {
            self::assertEquals($sentences[$i], $str);
        }
    }

    public function sentenceDataProvider(): \Iterator
    {
        // Лектор спросил: «В чем смысл названия курса?» Я попытался вспомнить, что он говорил на первой лекции, и воспроизвести его слова.
        yield ['One sentence.', ['One sentence.']];
        yield ['Second sentence.  And a third one 123.', ['Second sentence.', 'And a third one 123.']];
        yield ['Текст на русском. И еще предложение. 1, 2, 3 и т. д. Цифры, буквы, и т. п., могут встретиться.', [
            'Текст на русском.',
            'И еще предложение.',
            '1, 2, 3 и т. д.',
            'Цифры, буквы, и т. п., могут встретиться.',
        ]];
        yield ['Sentence \i1. Sentence 2. Sentence\I 3.', ['Sentence \i1.\I', '\iSentence 2.\I', '\iSentence\I 3.'], true];
        yield ['Sentence \i1. Sentence 2. Sentence\B 3.', ['Sentence \i1.\I', '\iSentence 2.\I', '\b\iSentence\B 3.\I'], true];
        yield ['\i\uSentence \b1\B. Sentence 2. Sentence 3.\U\I', ['\i\uSentence \b1\B.\U\I', '\i\uSentence 2.\U\I', '\i\uSentence 3.\U\I'], true];
        yield [
            'Поезд отправился из пункта А в пункт Б. Затем вернулся назад.',
            [
                'Поезд отправился из пункта А в пункт Б.',
                'Затем вернулся назад.',
            ]];
        yield [
            'Это пример абзаца. Он содержит несколько предложений. Каждое предложение заканчивается точкой! Иногда используется вопросительный знак? И восклицательный знак! Иногда используются многоточия... Но это не всегда так.',
            [
                'Это пример абзаца.',
                'Он содержит несколько предложений.',
                'Каждое предложение заканчивается точкой!',
                'Иногда используется вопросительный знак?',
                'И восклицательный знак!',
                'Иногда используются многоточия...',
                'Но это не всегда так.',
            ]
        ];
        yield [
            '- Прямая речь тоже разбивается на предложения? – Да, безусловно! — Отлично, то, что нужно. - Пожалуйста.',
            [
                '- Прямая речь тоже разбивается на предложения?',
                '– Да, безусловно!',
                '— Отлично, то, что нужно.',
                '- Пожалуйста.',
            ]
        ];
        yield [
            '"Прямая речь может быть в другом синтаксисе", - сказал я. Противник добавил: «Как это скучно!» И следом: «Как это так». Такие дела.',
            [
                '"Прямая речь может быть в другом синтаксисе", - сказал я.',
                'Противник добавил: «Как это скучно!»',
                'И следом: «Как это так».',
                'Такие дела.',
            ]
        ];
        yield [
            'На первом курсе А. П. Петров вел математику. А. П. Петров делал это хорошо. Все радовались А.П. Петрову. А.П. Петров пел математику.',
            [
                'На первом курсе А. П. Петров вел математику.',
                'А. П. Петров делал это хорошо.',
                'Все радовались А.П. Петрову.',
                'А.П. Петров пел математику.',
            ]
        ];
        yield [
            'Last week, former director of the F.B.I. James B. Comey was fired. Mr. Comey was not available for comment.',
            [
                'Last week, former director of the F.B.I. James B. Comey was fired.',
                'Mr. Comey was not available for comment.',
            ]
        ];
        yield [
            'На первом курсе А. П. Петров (зам. декана), Д. А. Александров (преподаватель физики) и несколько студентов нашего факультета (я в том числе) отправились в Тверь на проведение окружного этапа школьной олимпиады по физике.',
            [
                'На первом курсе А. П. Петров (зам. декана), Д. А. Александров (преподаватель физики) и несколько студентов нашего факультета (я в том числе) отправились в Тверь на проведение окружного этапа школьной олимпиады по физике.',
            ]
        ];
    }

    /**
     * @dataProvider unbalancedInternalFormattingDataProvider
     * @param array<string, int> $expectedTags
     */
    public function testFixUnbalancedInternalFormatting(string $text, string $expected, array $expectedTags): void
    {
        $tags = [];
        self::assertSame($expected, StringHelper::fixUnbalancedInternalFormatting($text, $tags));
        self::assertEquals($expectedTags, $tags);
    }

    public function unbalancedInternalFormattingDataProvider(): \Iterator
    {
        yield [
            '\\iThis is \\bformatted text\\I with \\Bspecial characters\\i.',
            '\\iThis is \\bformatted text\\I with \\Bspecial characters\\i.\\I',
            ['i' => 1, 'b' => 0],
        ];
        yield [
            'Normal text with escaped formatting symbols like \\\\draw or \\\\inline or \\\\\\\\uuu.',
            'Normal text with escaped formatting symbols like \\\\draw or \\\\inline or \\\\\\\\uuu.',
            [],
        ];
        yield ['', '', []];
        yield ['456789i', '456789i', []];
        yield [
            '456789\\I',
            '\\i456789\\I',
            ['i' => -1],
        ];
        yield [
            '456789\\\\I',
            '456789\\\\I',
            [],
        ];
        yield [
            '456789\\\\\\I',
            '\\i456789\\\\\\I',
            ['i' => -1],
        ];
        yield [
            '456789\\\\\\\\I',
            '456789\\\\\\\\I',
            [],
        ];
        yield [
            '456789\\\\\\\\\\I',
            '\\i456789\\\\\\\\\\I',
            ['i' => -1],
        ];
        yield [
            '\\u456789',
            '\\u456789\\U',
            ['u' => 1],
        ];
        yield [
            '\\u\\D\\\\I\\b',
            '\\d\\u\\D\\\\I\\b\\B\\U',
            ['d' => -1, 'u' => 1, 'b' => 1],
        ];
        yield [
            '\i123 \b456 \i789',
            '\i123 \b456 \i789\B\I\I', // NOTE: This not what one expects. Current implementation does not account for the same nested tags since they do not make sense
            ['i' => 2, 'b' => 1],
        ];
        yield [
            '\I 123 \i',
            '\I 123 \i',
            ['i' => 0],
        ];
    }

    /**
     * @dataProvider getUnbalancedInternalFormattingDataProvider
     * @param array{list<string>, list<string>} $expected
     */
    public function testGetUnbalancedInternalFormatting(string $text, array $expected): void
    {
        self::assertEquals($expected, StringHelper::getUnbalancedInternalFormatting($text));
    }

    public function getUnbalancedInternalFormattingDataProvider(): \Iterator
    {
        yield [
            '\\iThis is \\bformatted text\\I with \\Bspecial characters\\i.',
            [['i'], []],
        ];
        yield [
            'Normal text with escaped formatting symbols like \\\\draw or \\\\inline or \\\\\\\\uuu.',
            [[], []],
        ];
        yield ['', [[], []]];
        yield ['456789i', [[], []]];
        yield [
            '456789\\I',
            [[], ['I']],
        ];
        yield [
            '456789\\\\I',
            [[], []],
        ];
        yield [
            '456789\\\\\\I',
            [[], ['I']],
        ];
        yield [
            '456789\\\\\\\\I',
            [[], []],
        ];
        yield [
            '456789\\\\\\\\\\I',
            [[], ['I']],
        ];
        yield [
            '\\u456789',
            [['u'], []],
        ];
        yield [
            '\\u\\D\\\\I\\b',
            [['u', 'b'], ['D']],
        ];
        yield [
            '\i123 \b456 \i789',
            [['i', 'b', 'i'], []],
        ];
        yield [
            '\I 123 \i',
            [['i'], ['I']],
        ];
    }
}
