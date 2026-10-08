<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace unit\Register\Module\Search;

use Codeception\Test\Unit;
use Register\Module\Search\Morphology\ChurchSlavonicNormalizer;
use Register\Module\Search\Morphology\HistoricalRussianNormalizer;
use Register\Module\Search\Morphology\HybridWordNormalizer;
use Register\Module\Search\Morphology\OpenCorporaDictionary;
use Register\Module\Search\Morphology\PreReformRussianNormalizer;
use Register\Rose\Entity\Indexable;
use Register\Rose\Entity\Query;
use Register\Rose\Entity\ResultItem;
use Register\Rose\Finder;
use Register\Rose\Indexer;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\File\SingleFileArrayStorage;

final class SnippetSelectionTest extends Unit
{
    /** @dataProvider storageProvider */
    public function testFragmentsCoverDifferentQueryTerms(string $storageType): void
    {
        $finder = $this->finder($storageType, new Indexable('example', 'Example',
            'Quasar lights northern sky. Quasar travels across dark space. '
            . 'Quasar appears above mountain. Engine needs regular care.',
        ));

        $snippet = $finder->find(new Query('quasar engine'))->getItems()[0]->getSnippet();

        self::assertStringContainsString('<mark>Quasar</mark>', $snippet);
        self::assertStringContainsString('<mark>Engine</mark> needs regular care.', $snippet);
        self::assertLessThan(strpos($snippet, 'Engine'), strpos($snippet, 'Quasar'));
        self::assertStringNotContainsString('above mountain', $snippet);
    }

    /** @dataProvider storageProvider */
    public function testActualPhraseBeatsDisconnectedMentions(string $storageType): void
    {
        $finder = $this->finder($storageType, new Indexable('example', 'Example',
            'Red comet glows beneath blue sky. Red comet shines beside blue cloud. '
            . 'Red comet moves across blue horizon. A red blue ribbon hangs nearby.',
        ));

        $result = $finder->find(new Query('"red blue" comet'));
        $snippet = $result->getItems()[0]->getSnippet();

        self::assertSame(1, $result->getTotalCount());
        self::assertStringContainsString('<mark>red blue</mark> ribbon hangs nearby.', $snippet);
        self::assertStringContainsString('comet', $snippet);
        self::assertSame(['example'], array_map(static fn(ResultItem $item): string => $item->getId(), $result->getItems()));
    }

    /** @dataProvider storageProvider */
    public function testDifferentQuotedPhrasesEachGetAnExcerpt(string $storageType): void
    {
        $finder = $this->finder($storageType, new Indexable('example', 'Example',
            'Red green blue gold paint covers first wall. Red green blue gold paint covers second wall. '
            . 'Red green blue gold paint covers third wall. A red blue ribbon hangs here. '
            . 'A green gold ribbon hangs there.',
        ));

        $snippet = $finder->find(new Query('"red blue" "green gold"'))->getItems()[0]->getSnippet();

        self::assertStringContainsString('<mark>red blue</mark> ribbon hangs here.', $snippet);
        self::assertStringContainsString('<mark>green gold</mark> ribbon hangs there.', $snippet);
    }

    /** @dataProvider storageProvider */
    public function testMorphologicalPhraseMatchesAreVisible(string $storageType): void
    {
        $finder = $this->finder($storageType, new Indexable('example', 'Пример',
            'Дети рядом играют на площадке. Дети весело играют во дворе. '
            . 'Дети постоянно играют в парке. Ребёнок играл на сцене.',
        ));

        $snippet = $finder->find(new Query('"дети играют"'))->getItems()[0]->getSnippet();

        self::assertStringContainsString('<mark>Ребёнок играл</mark> на сцене.', $snippet);
    }

    /** @dataProvider storageProvider */
    public function testPhraseAcrossSentenceBoundaryRemainsComplete(string $storageType): void
    {
        $finder = $this->finder($storageType, new Indexable('example', 'Example',
            'Blue green sky grass covers first field. Blue green sky grass covers second field. '
            . 'Blue green sky grass covers third field. Blue sky. Green grass.',
        ));

        $snippet = $finder->find(new Query('"blue sky green grass"'))->getItems()[0]->getSnippet();

        self::assertStringContainsString('Blue sky. Green grass.', strip_tags($snippet));
    }

    /** @dataProvider storageProvider */
    public function testAlternativeLemmasStillCoverOnlyOneQueryWord(string $storageType): void
    {
        $finder = $this->finder($storageType, new Indexable('example', 'Пример',
            'Сталь стала материалом первой башни. Сталь стала материалом второй башни. '
            . 'Сталь стала материалом третьей башни. Корабль прибыл в тихую гавань.',
        ));

        $snippet = $finder->find(new Query('стали корабль'))->getItems()[0]->getSnippet();

        self::assertStringContainsString('<mark>Корабль</mark> прибыл', $snippet);
        self::assertStringContainsString('<mark>Сталь стала</mark>', $snippet);
    }

    /** @dataProvider storageProvider */
    public function testNumericTokensRemainUsableForCoverage(string $storageType): void
    {
        $finder = $this->finder($storageType, new Indexable('example', 'Example',
            'Quasar lights northern sky. Quasar travels across dark space. '
            . 'Quasar appears above mountain. Number 42 answers the question.',
        ));

        self::assertStringContainsString('<mark>42</mark>', $finder->find(new Query('quasar 42'))->getItems()[0]->getSnippet());
    }

    /** @dataProvider storageProvider */
    public function testJoinedPhraseKeepsBalancedFormattingAndNaturalOrder(string $storageType): void
    {
        $finder = $this->finder($storageType, new Indexable('example', 'Example',
            '<p>Blue <b>sky</b>. Green <i>grass</i>.</p><p>A comet visits the distant planet &amp; moon.</p>',
        ));

        $snippet = $finder->find(new Query('"blue sky green grass" comet'))->getItems()[0]->getFormattedSnippet();

        self::assertStringContainsString('<b>sky</b>', $snippet);
        self::assertStringContainsString('<i>grass</i>', $snippet);
        self::assertStringContainsString('&amp;', $snippet);
        self::assertLessThan(strpos($snippet, 'comet'), strpos($snippet, 'Blue'));
        self::assertSame(1, substr_count($snippet, 'Blue'));
        self::assertSame(1, substr_count($snippet, 'Green'));
    }

    /** @dataProvider storageProvider */
    public function testRepeatedSentencesNeededForAPhraseAreNotDeduplicated(string $storageType): void
    {
        $finder = $this->finder($storageType, new Indexable('example', 'Example',
            'Red blue.',
        ));
        self::assertSame([], $finder->find(new Query('"red blue red blue"'))->getItems());

        $finder = $this->finder($storageType, new Indexable('example', 'Example',
            'Red blue. Red blue.',
        ));

        self::assertSame('Red blue. Red blue.', strip_tags($finder->find(new Query('"red blue red blue"'))->getItems()[0]->getSnippet()));
    }

    /** @dataProvider storageProvider */
    public function testTitleOnlyPhraseDoesNotInventAnOccurrenceInTheBody(string $storageType): void
    {
        $finder = $this->finder($storageType, new Indexable('example', 'Red blue',
            'A red comet crosses the blue sky. Another red comet reaches a blue cloud.',
        ));

        $snippet = $finder->find(new Query('"red blue"'))->getItems()[0]->getSnippet();

        self::assertStringNotContainsString('red blue', mb_strtolower(strip_tags($snippet)));
        self::assertStringContainsString('<mark>red</mark>', $snippet);
    }

    /** @return \Iterator<string, array{string}> */
    public static function storageProvider(): \Iterator
    {
        yield 'SQL' => ['sql'];
        yield 'file' => ['file'];
    }

    private function finder(string $storageType, Indexable $document): Finder
    {
        $normalizer = new HybridWordNormalizer(
            new HistoricalRussianNormalizer(
                new ChurchSlavonicNormalizer(),
                new PreReformRussianNormalizer(),
                new OpenCorporaDictionary(dirname(__DIR__, 5) . '/_include/src/Register/Module/Search/resources/morphology/ru'),
            ),
            new PorterStemmerRussian(new PorterStemmerEnglish()),
        );
        $storage = $storageType === 'sql'
            ? new PdoStorage(new \PDO('sqlite::memory:'), 'snippet_test_')
            : new SingleFileArrayStorage(__DIR__ . '/../../../../tmp/snippet-selection.php');
        if ($storage instanceof PdoStorage) {
            $storage->erase();
        }

        (new Indexer($storage, $normalizer))->index($document);

        return (new Finder($storage, $normalizer))->setHighlightTemplate('<mark>%s</mark>');
    }
}
