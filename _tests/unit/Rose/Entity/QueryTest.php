<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 */

namespace S2\Rose\Test\Entity;

use Codeception\Test\Unit;
use S2\Rose\Entity\Query;

/**
 * @group entity
 * @group query
 */
final class QueryTest extends Unit
{
    public function testFilterInput(): void
    {
        // Tests for splitting strings by special delimiters
        self::assertEquals([1, 2], (new Query('1|||2'))->valueToArray());
        self::assertEquals([1, 2], (new Query('1\\\\\\2'))->valueToArray());
        self::assertEquals(['a', 'b'], (new Query('a/b'))->valueToArray());
        self::assertEquals(['a', 'b'], (new Query(' a   b   '))->valueToArray());
        self::assertEquals(['..'], (new Query('..'))->valueToArray());
        self::assertEquals(['...'], (new Query('...'))->valueToArray());
        self::assertEquals(['a..b'], (new Query('a..b'))->valueToArray());

        // Tests for replacing numbers
        self::assertEquals(['1.2'], (new Query('1,2'))->valueToArray());
        // self::assertEquals(['-1.2'], (new Query('-1,2'))->valueToArray());
        self::assertEquals(['1.2'], (new Query('1.2'))->valueToArray());

        // Tests for replacing typographic quotes
        self::assertEquals(['"', 'text'], (new Query('«text»'))->valueToArray());
        self::assertEquals(['"', 'text'], (new Query('“text”'))->valueToArray());

        // Tests for replacing dashes
        self::assertEquals(['a--b'], (new Query('a--b'))->valueToArray());
        self::assertEquals(['a—b'], (new Query('a---b'))->valueToArray()); // --- to mdash
        self::assertEquals(['a—b'], (new Query('a–b'))->valueToArray()); // ndash to mdash
        self::assertEquals(['a-b'], (new Query('a−b'))->valueToArray()); // Minus to hyphen

        // Test for replacing line breaks and extra spaces
        self::assertEquals(['a', 'b'], (new Query("a\n\nb"))->valueToArray());
        self::assertEquals(['a', 'b'], (new Query("a \t   b"))->valueToArray());

        // Tests for separating special characters
        self::assertEquals(['a!b'], (new Query('a!b'))->valueToArray());
        self::assertEquals(['!', 'ab'], (new Query('!ab'))->valueToArray());
        self::assertEquals(['!', 'a!b'], (new Query('!a!b'))->valueToArray());
        self::assertEquals(['(', 'word', ')'], (new Query('(word)'))->valueToArray());
        self::assertEquals(['mysql', '--all-databases'], (new Query('mysql --all-databases'))->valueToArray());

        // Test for replacing "ё" with "е"
        self::assertEquals(['ё', 'полет', 'field'], (new Query('ё полёт field'))->valueToArray());

        // Tests for handling commas
        self::assertEquals(['a', ',', 'b'], (new Query('a,b'))->valueToArray());
        self::assertEquals(['a', ',,', 'b'], (new Query('a,,b'))->valueToArray());
        self::assertEquals(['a', ',,,', 'b'], (new Query('a,,,b'))->valueToArray());

        // Tests for removing long words
        self::assertEquals(['a', 'c'], (new Query('a ' . str_repeat('b', 101) . ' c'))->valueToArray());

        // Tests for compatibility of multiple rules
        self::assertEquals(['a—b', '"', 'text'], (new Query('a–b «text»'))->valueToArray());
        self::assertEquals(['a', ',', 'b'], (new Query(" a, \n   b "))->valueToArray());
        self::assertEquals(
            ['похоже', ',', 'лучшие', 'времена', 'наступили', 'я', 'решил', 'доработать', 'и', 'опубликовать', 'движок'],
            (new Query('Похоже, лучшие времена наступили. Я решил доработать и опубликовать движок.'))->valueToArray()
        );

        // Invalid inputs
        self::assertSame([], (new Query(null))->valueToArray());
        self::assertSame([], (new Query(['foo' => 'bar']))->valueToArray());
        self::assertSame(['ре'], (new Query(rawurldecode('%D1%80%D0%B5%D0')))->valueToArray());
    }
}
