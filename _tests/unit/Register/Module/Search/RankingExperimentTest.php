<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace unit\Register\Module\Search;

use Codeception\Test\Unit;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Tools\Search\RankingExperiment;
use Register\Tools\Search\RelevanceBenchmark;

require_once dirname(__DIR__, 5) . '/tools/search/RelevanceBenchmark.php';

final class RankingExperimentTest extends Unit
{
    public function testRarityIsFinitePositiveAndDistinguishesRareTerms(): void
    {
        self::assertSame(1.0, RankingExperiment::rarityWeight(10000, 1));
        self::assertGreaterThan(2.5, RankingExperiment::rarityWeight(10000, 10) / RankingExperiment::rarityWeight(10000, 1000));
        self::assertGreaterThan(0.0, RankingExperiment::rarityWeight(10000, 10000));
        self::assertSame(1.0, RankingExperiment::rarityWeight(0, 0));
        self::assertSame(1.0, RankingExperiment::rarityWeight(1, 100));
    }

    public function testRepeatsGrowSmoothlyWithoutARewardForUnlimitedRepetition(): void
    {
        self::assertSame(0.0, RankingExperiment::repeatWeight(0));
        self::assertSame(1.0, RankingExperiment::repeatWeight(1));
        self::assertSame(1.75, RankingExperiment::repeatWeight(7));
        self::assertGreaterThan(RankingExperiment::repeatWeight(7), RankingExperiment::repeatWeight(100));
        self::assertLessThan(2.0, RankingExperiment::repeatWeight(100));
    }

    public function testRarityAndSoftCoverageCanOvercomeTheCompleteMatchVeto(): void
    {
        $documents = [
            ['id' => 'focused', 'title' => 'Quartz guide', 'content' => 'Specialized reference.', 'keywords' => ''],
            ['id' => 'complete', 'title' => 'General notes', 'content' => 'Quartz ' . str_repeat('unrelated ', 80) . 'archive.', 'keywords' => ''],
        ];
        for ($i = 0; $i < 100; ++$i) {
            $documents[] = ['id' => 'noise-' . $i, 'title' => 'Archive', 'content' => 'Common reference.', 'keywords' => ''];
        }

        $report = (new RelevanceBenchmark(new PorterStemmerEnglish(), experiments: true))->run([
            'documents' => $documents,
            'queries' => [['query' => 'quartz archive', 'relevance' => ['focused' => 3, 'complete' => 1]]],
        ]);
        $rankings = $report['results'][0]['rankings'];
        self::assertSame('complete', $rankings['coverage'][0]);
        self::assertSame('complete', $rankings['smooth_tf'][0]);
        self::assertSame('complete', $rankings['idf_hard'][0]);
        foreach (['idf_soft', 'idf_smooth', 'idf_smooth_c4', 'idf_smooth_c8'] as $model) {
            self::assertSame('focused', $rankings[$model][0], $model);
            self::assertCount(102, $rankings[$model]);
        }
    }

    public function testSmoothRepeatsAreAnIndependentAblation(): void
    {
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish(), experiments: true))->run([
            'documents' => [
                ['id' => 'repeated', 'title' => 'General notes', 'content' => str_repeat('quartz ', 20), 'keywords' => ''],
                ['id' => 'focused', 'title' => 'Specialized notes', 'content' => 'Quartz.', 'keywords' => '', 'relevanceRatio' => 3.0],
            ],
            'queries' => [['query' => 'quartz', 'relevance' => ['focused' => 3]]],
        ]);
        self::assertSame('repeated', $report['results'][0]['rankings']['coverage'][0]);
        self::assertSame('focused', $report['results'][0]['rankings']['smooth_tf'][0]);
    }

    public function testSingleTermExactFormsRemainProtected(): void
    {
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish(), experiments: true))->run([
            'documents' => [
                ['id' => 'literal', 'title' => 'General notes', 'content' => 'Cat.', 'keywords' => ''],
                ['id' => 'inflection', 'title' => 'Cats', 'content' => str_repeat('cats ', 30), 'keywords' => ''],
            ],
            'queries' => [['query' => 'cat', 'relevance' => ['literal' => 3]]],
        ]);
        foreach (RankingExperiment::MODELS as $model) {
            self::assertSame('literal', $report['results'][0]['rankings'][$model][0], $model);
        }
    }

    public function testPhraseConstraintsAreAppliedBeforeAllExperimentalRanking(): void
    {
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish(), experiments: true))->run([
            'documents' => [
                ['id' => 'phrase', 'title' => 'Red lamp', 'content' => 'Exact adjacent words.', 'keywords' => ''],
                ['id' => 'inflected-phrase', 'title' => 'Red lamps', 'content' => 'Adjacent normalized words.', 'keywords' => ''],
                ['id' => 'separated', 'title' => 'Red bright lamp', 'content' => 'Words with a gap.', 'keywords' => ''],
                ['id' => 'cross-fields', 'title' => 'Red', 'content' => 'Lamp.', 'keywords' => ''],
            ],
            'queries' => [['query' => '"red lamp"', 'relevance' => ['phrase' => 3, 'inflected-phrase' => 2]]],
        ]);
        foreach (RankingExperiment::MODELS as $model) {
            $ids = $report['results'][0]['rankings'][$model];
            sort($ids);
            self::assertSame(['inflected-phrase', 'phrase'], $ids, $model);
        }
    }

    public function testDateOrderingIncludesDatesBeforeTheEpochAndUndatedLast(): void
    {
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish(), experiments: true))->run([
            'documents' => [
                ['id' => 'a-undated', 'title' => 'Archive', 'content' => 'Common reference.', 'keywords' => ''],
                ['id' => 'b-old', 'title' => 'Archive', 'content' => 'Common reference.', 'keywords' => '', 'publishedAt' => -1000000],
                ['id' => 'c-new', 'title' => 'Archive', 'content' => 'Common reference.', 'keywords' => '', 'publishedAt' => 1],
            ],
            'queries' => [['query' => 'archive', 'relevance' => ['c-new' => 3]]],
        ]);
        foreach (RankingExperiment::MODELS as $model) {
            self::assertSame(['c-new', 'b-old', 'a-undated'], $report['results'][0]['rankings'][$model], $model);
        }
    }

    public function testExperimentsAreOptIn(): void
    {
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish()))->run([
            'documents' => [['id' => 'example', 'title' => 'Archive', 'content' => 'Common reference.', 'keywords' => '']],
            'queries' => [['query' => 'archive', 'relevance' => ['example' => 3]]],
        ]);
        self::assertSame(['legacy', 'coverage', 'bm25f', 'rarity'], array_keys($report['models']));
    }

    public function testAnUnsatisfiedPhraseDoesNotInventExperimentalCandidates(): void
    {
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish(), experiments: true))->run([
            'documents' => [['id' => 'separated', 'title' => 'Red bright lamp', 'content' => 'Common reference.', 'keywords' => '']],
            'queries' => [['query' => '"red lamp"', 'relevance' => ['separated' => 3]]],
        ]);
        foreach (RankingExperiment::MODELS as $model) {
            self::assertSame([], $report['results'][0]['rankings'][$model], $model);
            self::assertSame(0.0, $report['models'][$model]['hit1']);
        }
    }

    public function testAllCandidatesAreRetainedForBroadQueries(): void
    {
        $documents = [];
        for ($i = 0; $i < 1000; ++$i) {
            $documents[] = ['id' => \sprintf('noise-%04d', $i), 'title' => 'Archive', 'content' => 'Common reference.', 'keywords' => ''];
        }

        $documents[] = ['id' => 'z-relevant', 'title' => 'Archive', 'content' => 'Common reference.', 'keywords' => ''];
        $report = (new RelevanceBenchmark(new PorterStemmerEnglish(), experiments: true))->run([
            'documents' => $documents,
            'queries' => [['query' => 'archive', 'relevance' => ['z-relevant' => 3]]],
        ]);
        foreach (RankingExperiment::MODELS as $model) {
            self::assertCount(1001, $report['results'][0]['rankings'][$model], $model);
            self::assertSame('z-relevant', $report['results'][0]['rankings'][$model][1000]);
            self::assertEqualsWithDelta(1.0 / 1001.0, $report['models'][$model]['mrr'], 1.0e-12);
        }
    }
}
