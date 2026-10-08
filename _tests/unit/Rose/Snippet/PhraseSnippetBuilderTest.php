<?php

declare(strict_types = 1);

namespace Register\Rose\Test\Snippet;

use Codeception\Test\Unit;
use Register\Rose\Entity\Metadata\SnippetSource;
use Register\Rose\Snippet\PhraseSnippetBuilder;

final class PhraseSnippetBuilderTest extends Unit
{
    public function testJoinsOnlyAdjacentStoredSourcesWithTheirOriginalFormat(): void
    {
        $first = new SnippetSource('Red \\bblue\\B.', SnippetSource::FORMAT_INTERNAL, 0, 1);
        $second = new SnippetSource('Green \\igold\\I.', SnippetSource::FORMAT_INTERNAL, 2, 3);

        $result = (new PhraseSnippetBuilder())->extend([$second, $first], [0 => ['starts' => [0], 'length' => 4]]);

        self::assertCount(3, $result);
        self::assertSame([$first, $second], \array_slice($result, 0, 2));
        self::assertSame('Red \\bblue\\B. Green \\igold\\I.', $result[2]->getText());
        self::assertSame(SnippetSource::FORMAT_INTERNAL, $result[2]->getFormatId());
        self::assertSame(0, $result[2]->getMinPosition());
        self::assertSame(3, $result[2]->getMaxPosition());
    }

    /**
     * @dataProvider unavailableWindowProvider
     * @param list<SnippetSource> $sources
     */
    public function testDoesNotFabricateMissingOrOversizedWindows(array $sources, int $start, int $length): void
    {
        self::assertSame($sources, (new PhraseSnippetBuilder())->extend($sources, [0 => ['starts' => [$start], 'length' => $length]]));
    }

    /** @return \Iterator<string, array{list<SnippetSource>, int, int}> */
    public static function unavailableWindowProvider(): \Iterator
    {
        yield 'empty' => [[], 0, 4];
        yield 'one complete source' => [[new SnippetSource('red blue green gold', 0, 0, 3)], 0, 4];
        yield 'missing beginning' => [[new SnippetSource('green gold', 0, 2, 3)], 0, 4];
        yield 'missing end' => [[new SnippetSource('red blue', 0, 0, 1)], 0, 4];
        yield 'gap' => [[new SnippetSource('red blue', 0, 0, 1), new SnippetSource('green gold', 0, 3, 4)], 0, 5];
        yield 'different formats' => [[new SnippetSource('red blue', 0, 0, 1), new SnippetSource('green gold', 1, 2, 3)], 0, 4];
        yield 'too many parts' => [[
            new SnippetSource('red blue', 0, 0, 1), new SnippetSource('green gold', 0, 2, 3),
            new SnippetSource('black white', 0, 4, 5), new SnippetSource('pink purple', 0, 6, 7),
        ], 0, 8];
        yield 'too much text' => [[new SnippetSource(str_repeat('r', 2048), 0, 0, 1), new SnippetSource(str_repeat('g', 2048), 0, 2, 3)], 0, 4];
    }

    public function testAtMostThreeDistinctWindowsAreAddedPerPhrase(): void
    {
        $sources = [];
        for ($index = 0; $index < 5; ++$index) {
            $sources[] = new SnippetSource('red blue', 0, 4 * $index, 4 * $index + 1);
            $sources[] = new SnippetSource('green gold', 0, 4 * $index + 2, 4 * $index + 3);
        }

        $result = (new PhraseSnippetBuilder())->extend($sources, [0 => ['starts' => [0, 0, 0, 4, 8, 12, 16], 'length' => 4]]);

        self::assertCount(13, $result);
        self::assertSame([0, 4, 8], array_map(static fn(SnippetSource $source): int => $source->getMinPosition(), \array_slice($result, 10)));
    }
}
