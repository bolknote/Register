<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\Metadata\ImgCollection;
use S2\Rose\Entity\Metadata\SnippetSource;
use S2\Rose\Entity\TocEntry;
use S2\Rose\Entity\TocEntryWithMetadata;
use S2\Rose\Exception\UnknownException;
use S2\Rose\Helper\SnippetTextHelper;
use S2\Rose\Storage\Database\PdoStorage;

/** Finds semantically related indexed content on every supported database. */
final readonly class RecommendationFinder
{
    public function __construct(
        private PdoStorage $storage,
        private \PDO       $pdo,
        private string     $dbType,
        private string     $tablePrefix,
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $this->tablePrefix) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid search table prefix "%s".', $this->tablePrefix));
        }
    }

    /**
     * @return list<array<string, mixed>>
     * @throws \JsonException
     * @throws UnknownException
     */
    public function getSimilar(
        ExternalId $externalId,
        bool       $includeFormatting,
        ?int       $instanceId = null,
        int        $minCommonWords = 4,
        int        $limit = 10,
    ): array {
        if ($this->dbType !== 'sqlite') {
            return array_values($this->storage->getSimilar($externalId, $includeFormatting, $instanceId, $minCommonWords, $limit));
        }

        $tocTable      = $this->tablePrefix . 'toc';
        $snippetTable  = $this->tablePrefix . 'snippet';
        $fulltextTable = $this->tablePrefix . 'fulltext_index';
        $metadataTable = $this->tablePrefix . 'metadata';
        $instanceWhere = $instanceId === null ? '' : 'AND candidate_toc.instance_id = :candidate_instance_id';

        $sql = <<<SQL
            WITH original_words AS (
                SELECT
                    x.word_id,
                    x.toc_id,
                    length(x.positions) - length(replace(x.positions, ',', '')) AS original_repeat
                FROM {$fulltextTable} AS x
                JOIN {$tocTable} AS original_toc ON original_toc.id = x.toc_id
                WHERE original_toc.external_id = :external_id
                    AND original_toc.instance_id = :instance_id
                    AND length(x.positions) - length(replace(x.positions, ',', '')) < 200
            ),
            original_info AS (
                SELECT
                    original_words.word_id,
                    original_words.toc_id,
                    original_words.original_repeat,
                    count(all_items.toc_id) AS abundance
                FROM original_words
                JOIN {$fulltextTable} AS all_items ON all_items.word_id = original_words.word_id
                GROUP BY original_words.word_id, original_words.toc_id, original_words.original_repeat
                HAVING count(all_items.toc_id) < 100
            )
            SELECT
                candidate.toc_id,
                original_info.original_repeat,
                original_info.abundance,
                length(candidate.positions) - length(replace(candidate.positions, ',', '')) AS candidate_repeat,
                metadata.word_count
            FROM {$fulltextTable} AS candidate
            JOIN {$tocTable} AS candidate_toc ON candidate_toc.id = candidate.toc_id
            JOIN {$metadataTable} AS metadata ON metadata.toc_id = candidate.toc_id
            JOIN original_info
                ON original_info.word_id = candidate.word_id
                AND original_info.toc_id <> candidate.toc_id
            {$instanceWhere}
            SQL;

        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof \PDOStatement) {
                throw new UnknownException('Unable to prepare the SQLite recommendations query.');
            }

            $statement->bindValue('external_id', $externalId->getId(), \PDO::PARAM_STR);
            $statement->bindValue('instance_id', (int)$externalId->getInstanceId(), \PDO::PARAM_INT);
            if ($instanceId !== null) {
                $statement->bindValue('candidate_instance_id', $instanceId, \PDO::PARAM_INT);
            }

            $statement->execute();

            $scoreRows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            throw new UnknownException('Unable to fetch similar items from SQLite: ' . $exception->getMessage(), 0, $exception);
        }

        $candidates = $this->rankCandidates($scoreRows, $minCommonWords, $limit);
        if ($candidates === []) {
            return [];
        }

        $rows = $this->fetchCandidateRows($candidates, $tocTable, $snippetTable, $metadataTable);

        $recommendations = [];
        foreach ($candidates as $candidate) {
            $row = $rows[$candidate['toc_id']] ?? null;
            if ($row === null) {
                continue;
            }

            $row['relevance'] = $candidate['relevance'];
            $recommendations[] = $this->hydrateRecommendation($row, $includeFormatting);
        }

        return $recommendations;
    }

    /**
     * @param array<array-key, array<string, scalar|null>> $rows
     * @return list<array{toc_id: int, relevance: float}>
     */
    private function rankCandidates(array $rows, int $minCommonWords, int $limit): array
    {
        /** @var array<int, array{score: float, common_words: int, word_count: int}> $scores */
        $scores = [];
        foreach ($rows as $row) {
            $tocId = (int)$row['toc_id'];
            if (!isset($scores[$tocId])) {
                $scores[$tocId] = [
                    'score'        => 0.0,
                    'common_words' => 0,
                    'word_count'   => max(1, (int)$row['word_count']),
                ];
            }

            $originalRepeat  = (float)$row['original_repeat'];
            $abundance       = (float)$row['abundance'];
            $candidateRepeat = (float)$row['candidate_repeat'];
            $scores[$tocId]['score'] += $originalRepeat
                + exp(-$abundance / 30.0) * (1.0 + $candidateRepeat);
            ++$scores[$tocId]['common_words'];
        }

        $candidates = [];
        foreach ($scores as $tocId => $score) {
            if ($score['common_words'] < $minCommonWords) {
                continue;
            }

            $candidates[] = [
                'toc_id'    => $tocId,
                'relevance' => $score['score'] / sqrt((float)$score['word_count']),
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            $relevanceOrder = $right['relevance'] <=> $left['relevance'];

            return $relevanceOrder !== 0 ? $relevanceOrder : $left['toc_id'] <=> $right['toc_id'];
        });

        return array_slice($candidates, 0, max(0, $limit));
    }

    /**
     * @param list<array{toc_id: int, relevance: float}> $candidates
     * @return array<int, array<string, mixed>>
     * @throws UnknownException
     */
    private function fetchCandidateRows(
        array $candidates,
        string $tocTable,
        string $snippetTable,
        string $metadataTable,
    ): array {
        $placeholders = [];
        foreach (array_keys($candidates) as $index) {
            $placeholders[] = ':toc_id_' . $index;
        }

        $sql = <<<SQL
            SELECT
                metadata.word_count,
                metadata.images,
                toc.*,
                (
                    SELECT snippet
                    FROM {$snippetTable} AS snippet
                    WHERE snippet.toc_id = toc.id
                    ORDER BY snippet.max_word_pos
                    LIMIT 1
                ) AS snippet,
                (
                    SELECT format_id
                    FROM {$snippetTable} AS snippet
                    WHERE snippet.toc_id = toc.id
                    ORDER BY snippet.max_word_pos
                    LIMIT 1
                ) AS snippet_format_id,
                (
                    SELECT snippet
                    FROM {$snippetTable} AS snippet
                    WHERE snippet.toc_id = toc.id
                    ORDER BY snippet.max_word_pos
                    LIMIT 1 OFFSET 1
                ) AS snippet2,
                (
                    SELECT format_id
                    FROM {$snippetTable} AS snippet
                    WHERE snippet.toc_id = toc.id
                    ORDER BY snippet.max_word_pos
                    LIMIT 1 OFFSET 1
                ) AS snippet2_format_id
            FROM {$tocTable} AS toc
            JOIN {$metadataTable} AS metadata ON metadata.toc_id = toc.id
            WHERE toc.id IN (%s)
            SQL;

        try {
            $statement = $this->pdo->prepare(sprintf($sql, implode(', ', $placeholders)));
            if (!$statement instanceof \PDOStatement) {
                throw new UnknownException('Unable to prepare the SQLite recommendation details query.');
            }

            foreach ($candidates as $index => $candidate) {
                $statement->bindValue('toc_id_' . $index, $candidate['toc_id'], \PDO::PARAM_INT);
            }

            $statement->execute();

            /** @var list<array<string, mixed>> $fetchedRows */
            $fetchedRows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            throw new UnknownException('Unable to fetch SQLite recommendation details: ' . $exception->getMessage(), 0, $exception);
        }

        $rows = [];
        foreach ($fetchedRows as $row) {
            $rows[(int)$row['id']] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     * @throws \JsonException
     */
    private function hydrateRecommendation(array $row, bool $includeFormatting): array
    {
        $externalId = new ExternalId(
            (string)$row['external_id'],
            (int)$row['instance_id'] > 0 ? (int)$row['instance_id'] : null,
        );
        $tocEntry = new TocEntry(
            (string)$row['title'],
            (string)$row['description'],
            $this->hydrateDate($row),
            (string)$row['url'],
            (float)$row['relevance_ratio'],
            (string)$row['hash'],
        );

        $row['snippet'] = SnippetTextHelper::prepareForOutput(
            (string)($row['snippet'] ?? ''),
            (int)($row['snippet_format_id'] ?? SnippetSource::FORMAT_PLAIN_TEXT),
            $includeFormatting,
        );
        $row['snippet2'] = SnippetTextHelper::prepareForOutput(
            (string)($row['snippet2'] ?? ''),
            (int)($row['snippet2_format_id'] ?? SnippetSource::FORMAT_PLAIN_TEXT),
            $includeFormatting,
        );
        $row['tocWithMetadata'] = new TocEntryWithMetadata(
            $tocEntry,
            $externalId,
            ImgCollection::createFromJson((string)$row['images']),
        );

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDate(array $row): ?\DateTime
    {
        if (!isset($row['added_at'])) {
            return null;
        }

        try {
            $timezoneName = $row['timezone'] ?? null;
            $timezone     = is_string($timezoneName) && $timezoneName !== '' ? new \DateTimeZone($timezoneName) : null;

            return new \DateTime(
                (string)$row['added_at'],
                $timezone,
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
