<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace unit\Register\Module\Search;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use Register\Module\Search\Morphology\ChurchSlavonicNormalizer;
use Register\Module\Search\Morphology\HistoricalRussianNormalizer;
use Register\Module\Search\Morphology\HybridWordNormalizer;
use Register\Module\Search\Morphology\OpenCorporaDictionary;
use Register\Module\Search\Morphology\PreReformRussianNormalizer;
use Register\Rose\Extractor\ExtractorInterface;
use Register\Rose\Extractor\HtmlDom\DomExtractor;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;
use Register\Tools\Search\RelevanceBenchmark;

require_once dirname(__DIR__, 5) . '/tools/search/RelevanceBenchmark.php';

final class RelevanceBenchmarkTest extends Unit
{
    public function testDatasetLoadsOptionalPublicationMetadataAndKeepsDefaults(): void
    {
        $defaults = $this->loadSingleDocument([])['documents'][0];
        self::assertArrayHasKey('publishedAt', $defaults);
        self::assertArrayHasKey('relevanceRatio', $defaults);
        self::assertNull($defaults['publishedAt']);
        self::assertSame(1.0, $defaults['relevanceRatio']);

        $dated = $this->loadSingleDocument(['publishedAt' => 1672531200, 'relevanceRatio' => 2])['documents'][0];
        self::assertArrayHasKey('publishedAt', $dated);
        self::assertArrayHasKey('relevanceRatio', $dated);
        self::assertSame(1672531200, $dated['publishedAt']);
        self::assertSame(2.0, $dated['relevanceRatio']);
    }

    /** @param array<string, mixed> $metadata */
    #[DataProvider('invalidDocumentMetadata')]
    public function testDatasetRejectsInvalidPublicationMetadata(array $metadata): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->loadSingleDocument($metadata);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidDocumentMetadata(): array
    {
        return [
            'date string' => [['publishedAt' => '2023-01-01']],
            'date float' => [['publishedAt' => 1.5]],
            'ratio string' => [['relevanceRatio' => '1.25']],
            'ratio boolean' => [['relevanceRatio' => true]],
            'ratio null' => [['relevanceRatio' => null]],
            'ratio too small' => [['relevanceRatio' => 0.0]],
            'ratio too large' => [['relevanceRatio' => 10000.0]],
        ];
    }

    public function testPerfectRankingAndRepeatedIds(): void
    {
        $judgments = ['direct' => 3, 'related' => 2, 'incidental' => 1];
        $perfect = ['direct', 'related', 'incidental'];

        self::assertSame(
            ['hit1' => 1.0, 'hit3' => 1.0, 'mrr' => 1.0, 'ndcg10' => 1.0],
            RelevanceBenchmark::measure($perfect, $judgments),
        );
        self::assertSame(
            RelevanceBenchmark::measure($perfect, $judgments),
            RelevanceBenchmark::measure(['direct', 'direct', 'related', 'incidental'], $judgments),
        );
    }

    public function testMissingResultsReduceNdcg(): void
    {
        $metrics = RelevanceBenchmark::measure(['direct'], ['direct' => 3, 'related' => 2]);

        self::assertSame(1.0, $metrics['hit1']);
        self::assertEqualsWithDelta(0.7871546029909718, $metrics['ndcg10'], 1.0e-12);
    }

    public function testIncidentalMatchesDoNotCountAsSuccessfulSearches(): void
    {
        $metrics = RelevanceBenchmark::measure(['incidental', 'noise', 'direct'], ['direct' => 3, 'incidental' => 1]);

        self::assertSame(0.0, $metrics['hit1']);
        self::assertSame(1.0, $metrics['hit3']);
        self::assertEqualsWithDelta(1.0 / 3.0, $metrics['mrr'], 1.0e-12);
        self::assertSame(
            ['hit1' => 0.0, 'hit3' => 0.0, 'mrr' => 0.0, 'ndcg10' => 0.0],
            RelevanceBenchmark::measure([], ['direct' => 3]),
        );
    }

    public function testComparesLegacyAndCurrentRankingThroughTheIndexerAndFinder(): void
    {
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish()))->run([
            'documents' => [
                ['id' => 'complete', 'title' => 'Histories of theatres', 'content' => 'Dramatic art over time.', 'keywords' => ''],
                ['id' => 'partial', 'title' => 'History of bicycles', 'content' => 'Urban cycling.', 'keywords' => ''],
            ],
            'queries' => [
                ['query' => 'history theatre', 'relevance' => ['complete' => 3]],
            ],
        ]);

        self::assertSame(['partial', 'complete'], $report['results'][0]['rankings']['legacy']);
        self::assertSame(['complete', 'partial'], $report['results'][0]['rankings']['coverage']);
        self::assertSame(['complete', 'partial'], $report['results'][0]['rankings']['bm25f']);
        self::assertSame(0.0, $report['models']['legacy']['hit1']);
        self::assertSame(1.0, $report['models']['coverage']['hit1']);
    }

    public function testBundledQueriesDoNotRegressAgainstTheLegacyComparator(): void
    {
        $root = dirname(__DIR__, 5);
        $normalizer = new HybridWordNormalizer(
            new HistoricalRussianNormalizer(
                new ChurchSlavonicNormalizer(),
                new PreReformRussianNormalizer(),
                new OpenCorporaDictionary($root . '/_include/src/Register/Module/Search/resources/morphology/ru'),
            ),
            new PorterStemmerRussian(new PorterStemmerEnglish()),
        );
        $report = (new RelevanceBenchmark($normalizer))->run(
            RelevanceBenchmark::loadDataset($root . '/_tests/_resources/search/relevance.json'),
        );

        foreach ($report['results'] as $result) {
            self::assertGreaterThanOrEqual(
                $result['metrics']['legacy']['ndcg10'],
                $result['metrics']['coverage']['ndcg10'],
                $result['query'],
            );
        }
    }

    public function testPublicationDatesBreakScoreTiesForAllModels(): void
    {
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish()))->run([
            'documents' => [
                ['id' => 'a-undated', 'title' => 'History', 'content' => 'An archive.', 'keywords' => ''],
                ['id' => 'b-older', 'title' => 'History', 'content' => 'An archive.', 'keywords' => '', 'publishedAt' => 1609459200],
                ['id' => 'c-newer', 'title' => 'History', 'content' => 'An archive.', 'keywords' => '', 'publishedAt' => 1672531200],
            ],
            'queries' => [['query' => 'history', 'relevance' => ['c-newer' => 3]]],
        ]);

        foreach ($report['results'][0]['rankings'] as $model => $ranking) {
            self::assertSame(['c-newer', 'b-older', 'a-undated'], $ranking, $model);
        }
    }

    public function testBenchmarkUsesTheProvidedContentExtractor(): void
    {
        $extractor = $this->createMock(ExtractorInterface::class);
        $extractor->expects(self::once())->method('extract')
            ->with('<p>Unrelated original content.</p>')
            ->willReturn((new DomExtractor())->extract('<p>An archive.</p>'));
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish(), $extractor))->run([
            'documents' => [['id' => 'example', 'title' => 'Example', 'content' => '<p>Unrelated original content.</p>', 'keywords' => '']],
            'queries' => [['query' => 'archive', 'relevance' => ['example' => 3]]],
        ]);

        foreach ($report['results'][0]['rankings'] as $model => $ranking) {
            self::assertSame(['example'], $ranking, $model);
        }
    }

    public function testExternalRelevanceRatiosApplyToAllModels(): void
    {
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish()))->run([
            'documents' => [
                ['id' => 'a-normal', 'title' => 'History', 'content' => 'An archive.', 'keywords' => ''],
                ['id' => 'z-promoted', 'title' => 'History', 'content' => 'An archive.', 'keywords' => '', 'relevanceRatio' => 1.25],
            ],
            'queries' => [['query' => 'history', 'relevance' => ['z-promoted' => 3]]],
        ]);

        foreach ($report['results'][0]['rankings'] as $model => $ranking) {
            self::assertSame(['z-promoted', 'a-normal'], $ranking, $model);
        }
    }

    public function testBroadQueriesKeepFullRankingsWithoutRequestingEverySnippet(): void
    {
        $documents = [];
        for ($i = 0; $i < 1100; ++$i) {
            $documents[] = ['id' => \sprintf('noise-%04d', $i), 'title' => 'History', 'content' => 'An archive.', 'keywords' => ''];
        }

        $documents[] = ['id' => 'z-relevant', 'title' => 'History', 'content' => 'An archive.', 'keywords' => ''];
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish()))->run([
            'documents' => $documents,
            'queries' => [['query' => 'history', 'relevance' => ['z-relevant' => 3]]],
        ]);

        foreach ($report['results'][0]['rankings'] as $model => $ranking) {
            self::assertCount(1101, $ranking, $model);
            self::assertSame('z-relevant', $ranking[1100], $model);
            self::assertEqualsWithDelta(1.0 / 1101.0, $report['models'][$model]['mrr'], 1.0e-12, $model);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{documents: list<array{id: string, title: string, content: string, keywords: string, publishedAt?: int|null, relevanceRatio?: float}>, queries: list<array{query: string, relevance: array<string, int>}>}
     */
    private function loadSingleDocument(array $metadata): array
    {
        $path = tempnam(sys_get_temp_dir(), 'register-relevance-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary search dataset.');
        }

        try {
            file_put_contents($path, json_encode([
                'documents' => [['id' => 'example', 'title' => 'History', 'content' => 'An archive.'] + $metadata],
                'queries' => [['query' => 'history', 'relevance' => ['example' => 3]]],
            ], JSON_THROW_ON_ERROR));

            return RelevanceBenchmark::loadDataset($path);
        } finally {
            unlink($path);
        }
    }
}
