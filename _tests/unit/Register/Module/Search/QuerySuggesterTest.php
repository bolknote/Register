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
use Register\Module\Search\Service\KeyboardLayout;
use Register\Module\Search\Service\QuerySuggester;
use Register\Module\Search\Service\SingleEditVariants;
use Register\Rose\Entity\Indexable;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\Query;
use Register\Rose\Finder;
use Register\Rose\Indexer;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;
use Register\Rose\Storage\Database\PdoStorage;

final class QuerySuggesterTest extends Unit
{
    /** @dataProvider correctionProvider */
    public function testSuggestionsFindACompleteAnswerWithoutChangingTheOriginalQuery(string $text, string $expected): void
    {
        [$finder, $suggester] = $this->services();
        $query = new Query($text);
        $original = $finder->find($query);
        $originalIds = array_map(static fn(ExternalId $id): string => $id->toString(), $original->getSortedExternalIds()->toArray());
        $suggestions = $suggester->suggest($query, $original);

        self::assertContains($expected, $suggestions);
        self::assertLessThanOrEqual(3, \count($suggestions));
        self::assertSame($text, $query->getValue());
        self::assertSame($originalIds, array_map(static fn(ExternalId $id): string => $id->toString(), $original->getSortedExternalIds()->toArray()));
        self::assertGreaterThan(0, $finder->find(new Query($expected))->getTotalCount());
    }

    /** @return \Iterator<string, array{string, string}> */
    public static function correctionProvider(): \Iterator
    {
        yield 'wrong Latin layout' => ['gjbcr', 'поиск'];
        yield 'wrong Russian layout' => ['вфефифыу', 'database'];
        yield 'whole phrase in wrong layout' => ['"bcnjhbz ntfnhf"', '"история театра"'];
        yield 'Russian transposition and morphology' => ['истроия театра', 'история театра'];
        yield 'English transposition' => ['databsaes', 'databases'];
        yield 'missing English letter' => ['databse', 'database'];
        yield 'extra Russian letter' => ['защиита паролей', 'защита паролей'];
        yield 'case and surname' => ['Петтровой', 'Петровой'];
        yield 'repeated quoted typo' => ['"databsaes databsaes"', '"databases databases"'];
    }

    /** @dataProvider unchangedProvider */
    public function testCorrectQueriesAndUnsupportedExpansionsHaveNoSuggestions(string $text): void
    {
        [$finder, $suggester] = $this->services();
        $query = new Query($text);

        self::assertSame([], $suggester->suggest($query, $finder->find($query)));
    }

    /** @return \Iterator<string, array{string}> */
    public static function unchangedProvider(): \Iterator
    {
        yield 'exact uncommon surname' => ['Кавальская'];
        yield 'known inflection' => ['театра'];
        yield 'numbers' => ['43'];
        yield 'short code' => ['XYZ'];
        yield 'identifier' => ['databsaes_7'];
        yield 'correct words in a nonmatching phrase' => ['"театр история"'];
        yield 'several independent typos' => ['истроия щщиита'];
        yield 'bounded query length' => [str_repeat('x', 257)];
    }

    public function testKeyboardLayoutPreservesQuotesCaseAndPunctuation(): void
    {
        $layouts = new KeyboardLayout();

        self::assertSame(['"Поиск", поиск'], $layouts->alternatives('"Gjbcr", gjbcr'));
        self::assertSame(['пижама'], $layouts->alternatives('gb;fvf'));
        self::assertSame([], $layouts->alternatives('42'));
        self::assertSame([], $layouts->alternatives('PHP поиск'));
    }

    public function testCompleteCoverageOutsideTheCurrentPageIsStillDetected(): void
    {
        [$finder, $suggester] = $this->services();
        $query = (new Query('история театра'))->setLimit(1)->setOffset(1);
        $result = $finder->find($query);

        self::assertCount(0, $result->getItems());
        self::assertSame(2, $result->getMaxMatchedQueryTerms());
        self::assertSame([], $suggester->suggest($query, $result));
    }

    public function testEditOperationsUseCharactersInsteadOfUtf8Bytes(): void
    {
        $variants = new SingleEditVariants();

        self::assertContains('дерево', $variants->generate('дреево'));
        self::assertContains('дерево', $variants->generate('деревво'));
        self::assertContains('дерево', $variants->generate('дерво'));
        self::assertContains('дерево', $variants->generate('дерева'));
        self::assertNotContains('дерево', $variants->generate('дерево'));
        self::assertSame([], $variants->generate('12345'));
        self::assertSame([], $variants->generate(str_repeat('a', 25)));
    }

    /** @return array{Finder, QuerySuggester} */
    private function services(): array
    {
        $russian = new HistoricalRussianNormalizer(
            new ChurchSlavonicNormalizer(),
            new PreReformRussianNormalizer(),
            new OpenCorporaDictionary(dirname(__DIR__, 5) . '/_include/src/Register/Module/Search/resources/morphology/ru'),
        );
        $normalizer = new HybridWordNormalizer($russian, new PorterStemmerRussian(new PorterStemmerEnglish()));
        $storage = new PdoStorage(new \PDO('sqlite::memory:'), 'suggestion_test_');
        $storage->erase();

        $indexer = new Indexer($storage, $normalizer);
        foreach ([
            new Indexable('theatre', 'Истории театров', 'Развитие сценического искусства.'),
            new Indexable('password', 'Защита паролей', 'Безопасное хранение.'),
            new Indexable('family', 'Семейный архив', 'Письмо Анны Петровой.'),
            new Indexable('search', 'Поиск', 'Поиск по материалам.'),
            new Indexable('english', 'Database optimization', 'Databases databases need maintenance.'),
            new Indexable('uncommon', 'Кавальская', 'Документы и письма.'),
            new Indexable('common', 'Ковальская', 'Документы и письма.'),
        ] as $document) {
            $indexer->index($document);
        }

        $finder = new Finder($storage, $normalizer);

        return [$finder, new QuerySuggester($storage, $finder, $normalizer, $russian)];
    }
}
