<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Tools\Search;

use Register\Rose\Entity\ExactWord;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\FulltextQuery;
use Register\Rose\Entity\FulltextResult;
use Register\Rose\Entity\Indexable;
use Register\Rose\Entity\Query;
use Register\Rose\Entity\ResultSet;
use Register\Rose\Extractor\ExtractorInterface;
use Register\Rose\Finder;
use Register\Rose\Indexer;
use Register\Rose\Stemmer\StemmerInterface;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Rose\Storage\FulltextIndexContent;

/**
 * Offline comparisons over one corpus and one set of relevance judgments.
 *
 * @phpstan-type Document array{id: string, title: string, content: string, keywords: string, publishedAt?: int|null, relevanceRatio?: float}
 * @phpstan-type Question array{query: string, relevance: array<string, int>}
 * @phpstan-type Dataset array{documents: list<Document>, queries: list<Question>}
 * @phpstan-type Metrics array{hit1: float, hit3: float, mrr: float, ndcg10: float}
 * @phpstan-type QueryResult array{query: string, rankings: array<string, list<string>>, metrics: array<string, Metrics>}
 * @phpstan-type Report array{documents: int, queries: int, models: array<string, Metrics>, results: list<QueryResult>}
 */
final readonly class RelevanceBenchmark
{
    public function __construct(
        private StemmerInterface $normalizer,
        private ?ExtractorInterface $extractor = null,
    ) {
    }

    /** @return Dataset */
    public static function loadDataset(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \InvalidArgumentException('Cannot read search dataset: ' . $path);
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !\is_array($data['documents'] ?? null) || !\is_array($data['queries'] ?? null)) {
            throw new \InvalidArgumentException('A search dataset needs documents and queries arrays.');
        }

        $documents = [];
        $ids = [];
        foreach ($data['documents'] as $document) {
            if (!\is_array($document) || !\is_string($document['id'] ?? null) || $document['id'] === ''
                || !\is_string($document['title'] ?? null) || !\is_string($document['content'] ?? null)
                || !\is_string($document['keywords'] ?? '') || isset($ids[$document['id']])) {
                throw new \InvalidArgumentException('Documents need unique nonempty IDs, titles, content and optional keywords.');
            }

            $publishedAt = $document['publishedAt'] ?? null;
            $relevanceRatio = \array_key_exists('relevanceRatio', $document) ? $document['relevanceRatio'] : 1.0;
            if (($publishedAt !== null && !\is_int($publishedAt))
                || (!\is_int($relevanceRatio) && !\is_float($relevanceRatio))
                || !is_finite((float)$relevanceRatio) || $relevanceRatio < 0.001 || $relevanceRatio > 9999.0) {
                throw new \InvalidArgumentException('Optional publication dates must be integer timestamps, and relevance ratios must be finite numbers from 0.001 to 9999.');
            }

            $ids[$document['id']] = true;
            $documents[] = [
                'id' => $document['id'],
                'title' => $document['title'],
                'content' => $document['content'],
                'keywords' => $document['keywords'] ?? '',
                'publishedAt' => $publishedAt,
                'relevanceRatio' => (float)$relevanceRatio,
            ];
        }

        $queries = [];
        foreach ($data['queries'] as $question) {
            if (!\is_array($question) || !\is_string($question['query'] ?? null) || trim($question['query']) === ''
                || !\is_array($question['relevance'] ?? null)) {
                throw new \InvalidArgumentException('Queries need nonempty query text and relevance judgments.');
            }

            $relevance = [];
            foreach ($question['relevance'] as $id => $grade) {
                if (!isset($ids[$id]) || !\is_int($grade) || $grade < 1 || $grade > 3) {
                    throw new \InvalidArgumentException('Judgments must reference corpus IDs with grades from 1 to 3.');
                }

                $relevance[(string)$id] = $grade;
            }

            if ($relevance === [] || max($relevance) < 2) {
                throw new \InvalidArgumentException('Each query needs at least one relevant document (grade 2 or 3).');
            }

            $queries[] = ['query' => $question['query'], 'relevance' => $relevance];
        }

        if ($documents === [] || $queries === []) {
            throw new \InvalidArgumentException('A search dataset cannot be empty.');
        }

        return ['documents' => $documents, 'queries' => $queries];
    }

    /**
     * @param Dataset $dataset
     * @return Report
     */
    public function run(array $dataset): array
    {
        $storage = new BenchmarkStorage(new \PDO('sqlite::memory:'), 'benchmark_');
        $storage->erase();

        $indexer = new Indexer($storage, $this->normalizer, $this->extractor);
        $dates = [];
        foreach ($dataset['documents'] as $document) {
            $date = $document['publishedAt'] ?? null;
            $indexable = (new Indexable($document['id'], $document['title'], $document['content']))
                ->setKeywords($document['keywords'])
                ->setDate($date === null ? null : (new \DateTime())->setTimestamp($date))
                ->setRelevanceRatio($document['relevanceRatio'] ?? 1.0);
            $indexer->index($indexable);
            $dates[$indexable->getExternalId()->toString()] = $date;
        }

        $finder = new BenchmarkFinder($storage, $this->normalizer);
        $zero = ['hit1' => 0.0, 'hit3' => 0.0, 'mrr' => 0.0, 'ndcg10' => 0.0];
        $totals = ['legacy' => $zero, 'coverage' => $zero, 'bm25f' => $zero];
        $results = [];
        foreach ($dataset['queries'] as $question) {
            $query = new Query($question['query']);
            $fulltextQuery = new FulltextQuery($query->getSearchWords(), $this->normalizer);
            $index = $storage->fulltextResultByWords($fulltextQuery->getWordsWithStems());
            $result = $finder->find($query);
            $scores = $result->getSortedRelevanceByExternalId();
            [$legacyScores, $legacyExact] = $this->legacyScores($query, $storage);
            $exact = [];
            $coverage = [];
            $positions = $fulltextQuery->getRankingWordPositions();
            foreach ($index->toArray() as $word => $bags) {
                foreach ($bags as $bag) {
                    $id = $bag->getExternalId()->toString();
                    foreach ($positions[$word] ?? [] as $position) {
                        $coverage[$id][$position] = true;
                    }

                    if (ExactWord::decode((string)$word) !== null) {
                        if (isset($positions[$word])) {
                            $exact[$id] = ($exact[$id] ?? 0) + 1;
                        }
                    }
                }
            }

            $legacy = $this->rank($legacyScores, [], $legacyExact, $dates);
            $bm25Scores = $this->bm25Scores($fulltextQuery, $index, $storage->fieldLengths);
            $bm25 = $this->rank(array_intersect_key($bm25Scores + array_fill_keys(array_keys($scores), 0.0), $scores), $coverage, $exact, $dates);

            $rankings = [
                'legacy' => array_map(static fn(string $id): string => ExternalId::fromString($id)->getId(), $legacy),
                'coverage' => array_map(static fn(\Register\Rose\Entity\ResultItem $item): string => $item->getId(), $result->getItems()),
                'bm25f' => array_map(static fn(string $id): string => ExternalId::fromString($id)->getId(), $bm25),
            ];
            $metrics = [];
            foreach ($rankings as $model => $ranking) {
                $metrics[$model] = self::measure($ranking, $question['relevance']);
                foreach ($metrics[$model] as $metric => $value) {
                    $totals[$model][$metric] += $value;
                }
            }

            $results[] = ['query' => $question['query'], 'rankings' => $rankings, 'metrics' => $metrics];
        }

        $count = \count($results);
        foreach ($totals as &$total) {
            foreach ($total as &$value) {
                $value /= (float)max(1, $count);
            }

            unset($value);
        }

        unset($total);

        return ['documents' => \count($dataset['documents']), 'queries' => $count, 'models' => $totals, 'results' => $results];
    }

    /**
     * Recreate the previous query parsing and scoring without inheriting the
     * current Finder's phrase filters, which would hide retrieval differences.
     *
     * @return array{array<string, float|int>, array<string, int>}
     */
    private function legacyScores(Query $query, BenchmarkStorage $storage): array
    {
        $fulltextQuery = new FulltextQuery($query->valueToArray(), $this->normalizer);
        $index = $storage->fulltextResultByWords($fulltextQuery->getWordsWithStems());
        $result = new ResultSet();
        (new FulltextResult($fulltextQuery, $index, $storage->getTocSize(null)))->fillResultSet($result);
        $result->freeze();

        $exact = [];
        foreach ($index->toArray() as $word => $bags) {
            if (ExactWord::decode((string)$word) === null) {
                continue;
            }

            foreach ($bags as $bag) {
                $id = $bag->getExternalId()->toString();
                $exact[$id] = ($exact[$id] ?? 0) + 1;
            }
        }

        return [$result->getSortedRelevanceByExternalId(), $exact];
    }

    /**
     * @param array<string, float|int> $scores
     * @param array<string, array<int, true>> $coverage
     * @param array<string, int> $exact
     * @param array<string, int|null> $dates
     * @return list<string>
     */
    private function rank(array $scores, array $coverage, array $exact, array $dates): array
    {
        $ids = array_keys($scores);
        usort($ids, static function (string $left, string $right) use ($scores, $coverage, $exact, $dates): int {
            $leftRank = [\count($coverage[$left] ?? []), $exact[$left] ?? 0, $scores[$left] ?? 0.0];
            $rightRank = [\count($coverage[$right] ?? []), $exact[$right] ?? 0, $scores[$right] ?? 0.0];
            $order = $rightRank <=> $leftRank;

            if ($order !== 0) {
                return $order;
            }

            $dateOrder = ($dates[$right] ?? PHP_INT_MIN) <=> ($dates[$left] ?? PHP_INT_MIN);

            return $dateOrder !== 0 ? $dateOrder : strcmp($left, $right);
        });

        return $ids;
    }

    /**
     * @param list<string> $ranking
     * @param array<string, int> $relevance Grades: 3 directly answers, 2 relevant, 1 incidental; unjudged = 0.
     * @return Metrics
     */
    public static function measure(array $ranking, array $relevance): array
    {
        $ranking = array_values(array_unique($ranking));
        $first = null;
        $dcg = 0.0;
        foreach ($ranking as $position => $id) {
            $grade = $relevance[$id] ?? 0;
            if ($first === null && $grade >= 2) {
                $first = $position;
            }

            if ($position < 10) {
                $dcg += (2.0 ** (float)$grade - 1.0) / log((float)$position + 2.0, 2.0);
            }
        }

        $ideal = array_values($relevance);
        rsort($ideal);
        $idealDcg = 0.0;
        foreach (\array_slice($ideal, 0, 10) as $position => $grade) {
            $idealDcg += (2.0 ** (float)$grade - 1.0) / log((float)$position + 2.0, 2.0);
        }

        return [
            'hit1' => $first === 0 ? 1.0 : 0.0,
            'hit3' => $first !== null && $first < 3 ? 1.0 : 0.0,
            'mrr' => $first !== null ? 1.0 / ((float)$first + 1.0) : 0.0,
            'ndcg10' => $idealDcg > 0.0 ? $dcg / $idealDcg : 0.0,
        ];
    }

    /**
     * Experimental BM25F: field weights 5/3/1, b=0.75, k1=1.2, no phrase boost.
     * Lemma alternatives and exact keys must not multiply term frequency.
     *
     * @param array<string, array{int, int, int}> $lengths Title, keywords, body lengths for the whole corpus.
     * @return array<string, float>
     */
    private function bm25Scores(FulltextQuery $query, FulltextIndexContent $index, array $lengths): array
    {
        $averages = [0.0, 0.0, 0.0];
        $corpusSize = (float)\count($lengths);
        foreach ($lengths as $fields) {
            foreach ($fields as $field => $length) {
                $averages[$field] += (float)$length / max(1.0, $corpusSize);
            }
        }

        $terms = [];
        $ratios = [];
        $rankingPositions = $query->getRankingWordPositions();
        foreach ($index->toArray() as $word => $bags) {
            if (ExactWord::decode((string)$word) !== null) {
                continue;
            }

            foreach ($rankingPositions[$word] ?? [] as $term) {
                foreach ($bags as $bag) {
                    $id = $bag->getExternalId()->toString();
                    $ratios[$id] = $bag->getExternalRelevanceRatio();
                    foreach ([$bag->getTitlePositions(), $bag->getKeywordPositions(), $bag->getContentPositions()] as $field => $positions) {
                        foreach ($positions as $position) {
                            $terms[$term][$id][$field][$position] = true;
                        }
                    }
                }
            }
        }

        $scores = [];
        $weights = [5.0, 3.0, 1.0];
        foreach ($terms as $documents) {
            $documentFrequency = (float)\count($documents);
            $idf = log(1.0 + ($corpusSize - $documentFrequency + 0.5) / ($documentFrequency + 0.5));
            foreach ($documents as $id => $fields) {
                $tf = 0.0;
                foreach ($fields as $field => $positions) {
                    $average = $averages[$field] > 0.0 ? $averages[$field] : 1.0;
                    $normalization = 0.25 + 0.75 * (float)($lengths[$id][$field] ?? 0) / $average;
                    $tf += $weights[$field] * (float)\count($positions) / $normalization;
                }

                $scores[$id] = ($scores[$id] ?? 0.0) + $idf * 2.2 * $tf / (1.2 + $tf) * ($ratios[$id] ?? 1.0);
            }
        }

        return $scores;
    }
}

/** Evaluate complete rankings without fetching thousands of display-only snippets. */
final class BenchmarkFinder extends Finder
{
    #[\Override]
    public function buildSnippets(array $relevanceByExternalIds, ResultSet $resultSet): void
    {
    }
}

/** Records field lengths before the index merges lemma variants at each position. */
final class BenchmarkStorage extends PdoStorage
{
    /** @var array<string, array{int, int, int}> */
    public array $fieldLengths = [];

    #[\Override]
    public function addToFulltextIndex(array $titleWords, array $keywords, array $contentWords, ExternalId $externalId): void
    {
        $this->fieldLengths[$externalId->toString()] = [
            $this->fieldLength($titleWords), $this->fieldLength($keywords), $this->fieldLength($contentWords),
        ];
        parent::addToFulltextIndex($titleWords, $keywords, $contentWords, $externalId);
    }

    /** @param array<int|string, string> $words */
    private function fieldLength(array $words): int
    {
        return \count(array_unique(array_map(static fn(int|string $position): int => (int)$position, array_keys($words))));
    }
}
