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
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;
use Register\Tools\Search\RelevanceBenchmark;

require_once dirname(__DIR__, 5) . '/tools/search/RelevanceBenchmark.php';

final class RelevanceBenchmarkTest extends Unit
{
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
}
