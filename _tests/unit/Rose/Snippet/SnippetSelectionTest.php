<?php

declare(strict_types = 1);

namespace Register\Rose\Test\Snippet;

use Codeception\Test\Unit;
use Register\Rose\Entity\Metadata\SnippetSource;
use Register\Rose\Entity\SnippetLine;
use Register\Rose\Snippet\SnippetSelection;
use Register\Rose\Stemmer\PorterStemmerEnglish;

final class SnippetSelectionTest extends Unit
{
    public function testJoinedPhraseSuppressesOverlappingPartsAndKeepsDocumentOrder(): void
    {
        $candidates = [
            0 => $this->candidate('Red blue.', 0, 1, 10.0),
            1 => $this->candidate('Green gold.', 2, 3, 10.0),
            2 => $this->candidate('Another answer.', 10, 11, 10.0),
            3 => $this->candidate('Red blue. Green gold.', 0, 3, 1.0),
        ];
        $selection = new SnippetSelection([0 => [0], 1 => [2], 2 => [10]], [0 => ['starts' => [0], 'length' => 4]]);

        self::assertSame([3, 2], $selection->select($candidates, 3));
    }

    public function testDuplicateTextCannotConsumeAnotherExcerptSlot(): void
    {
        $selection = new SnippetSelection([0 => [0, 4], 1 => [10]], []);

        self::assertSame([1, 2], $selection->select([
            0 => $this->candidate('Same answer.', 0, 1, 10.0),
            1 => $this->candidate('Same answer.', 4, 5, 20.0),
            2 => $this->candidate('Another answer.', 10, 11, 1.0),
        ], 3));
    }

    public function testIncompletePhraseFragmentsAreNotTreatedAsWholeOccurrences(): void
    {
        $selection = new SnippetSelection([], [0 => ['starts' => [0], 'length' => 4]]);

        self::assertSame([2], $selection->select([
            0 => $this->candidate('Partial answer.', 0, 1, 20.0),
            1 => $this->candidate('Other partial.', 2, 3, 20.0),
            2 => $this->candidate('Whole phrase.', 0, 3, 1.0),
        ], 1));
    }

    public function testEqualScoresUseDocumentPositionAndRespectTheLimit(): void
    {
        $selection = new SnippetSelection([], []);
        $candidates = [
            0 => $this->candidate('Later answer.', 10, 11, 1.0),
            1 => $this->candidate('Earlier answer.', 0, 1, 1.0),
        ];

        self::assertSame([1], $selection->select($candidates, 1));
        self::assertSame([], $selection->select($candidates, 0));
        self::assertSame([], $selection->select([], 3));
    }

    /** @return array{line: SnippetLine, min: int, max: int} */
    private function candidate(string $text, int $min, int $max, float $weight): array
    {
        return [
            'line' => new SnippetLine($text, SnippetSource::FORMAT_PLAIN_TEXT, new PorterStemmerEnglish(), [], $weight),
            'min' => $min,
            'max' => $max,
        ];
    }
}
