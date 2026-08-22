<?php

declare(strict_types = 1);

/**
 * @copyright 2017-2018 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Test\Entity;

use Codeception\Test\Unit;
use Register\Rose\Entity\WordPositionContainer;

/**
 * Class WordPositionContainerTest
 *
 * @group container
 */
final class WordPositionContainerTest extends Unit
{
    public function testClosestDistance(): void
    {
        $container = new WordPositionContainer([
            'word1' => [23, 56, 74],
            'word2' => [2, 57],
        ]);

        self::assertSame(1, $container->getClosestDistanceBetween('word1', 'word2', 0));
        self::assertSame(-1, $container->getClosestDistanceBetween('word2', 'word1', 0));
        self::assertSame(23 - 2 - 20, $container->getClosestDistanceBetween('word2', 'word1', 20));
        self::assertSame(23 - 2 - 25, $container->getClosestDistanceBetween('word2', 'word1', 25));
    }

    public function testCompare(): void
    {
        $container = new WordPositionContainer();
        foreach (explode(' ', 'Циркуляция вектора напряженности электростатического поля вдоль замкнутого контура всегда равна нулю') as $k => $word) {
            $container->addWordAt($word, $k);
        }

        self::assertEquals([['поля', 'нулю', 7]], $container->compareWith(new WordPositionContainer([
            'нулю' => [5],
            'нул'  => [5],
            'поля' => [6],
            'пол'  => [6],
        ])));

        self::assertEquals([['поля', 'нулю', 5]], $container->compareWith(new WordPositionContainer([
            'нулю' => [1],
            'нул'  => [1],
            'поля' => [0],
            'пол'  => [0],
        ])));

        self::assertEquals([
            ['вектора', 'поля', 2],
            ['вектора', 'контура', 4],
            ['поля', 'контура', 2],
        ], $container->compareWith(new WordPositionContainer([
            'вектора' => [1],
            'поля'    => [2],
            'контура' => [3],
        ])));
    }
}
