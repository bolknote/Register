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

        $this->registerSqliteMathFunctions();

        $tocTable      = $this->tablePrefix . 'toc';
        $snippetTable  = $this->tablePrefix . 'snippet';
        $fulltextTable = $this->tablePrefix . 'fulltext_index';
        $metadataTable = $this->tablePrefix . 'metadata';
        $instanceWhere = $instanceId === null ? '' : 'WHERE t.instance_id = :candidate_instance_id';

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
            ),
            relevance_info AS (
                SELECT
                    candidate.toc_id,
                    sum(
                        original_info.original_repeat
                        + register_recommendation_exp(-original_info.abundance / 30.0)
                            * (1 + length(candidate.positions) - length(replace(candidate.positions, ',', '')))
                    ) * register_recommendation_pow(metadata.word_count, -0.5) AS relevance,
                    metadata.word_count
                FROM {$fulltextTable} AS candidate
                JOIN {$metadataTable} AS metadata ON metadata.toc_id = candidate.toc_id
                JOIN original_info
                    ON original_info.word_id = candidate.word_id
                    AND original_info.toc_id <> candidate.toc_id
                GROUP BY candidate.toc_id, metadata.word_count
                HAVING count(*) >= :min_common_words
            )
            SELECT
                relevance_info.*,
                metadata.images,
                t.*,
                (
                    SELECT snippet
                    FROM {$snippetTable} AS snippet
                    WHERE snippet.toc_id = t.id
                    ORDER BY snippet.max_word_pos
                    LIMIT 1
                ) AS snippet,
                (
                    SELECT format_id
                    FROM {$snippetTable} AS snippet
                    WHERE snippet.toc_id = t.id
                    ORDER BY snippet.max_word_pos
                    LIMIT 1
                ) AS snippet_format_id,
                (
                    SELECT snippet
                    FROM {$snippetTable} AS snippet
                    WHERE snippet.toc_id = t.id
                    ORDER BY snippet.max_word_pos
                    LIMIT 1 OFFSET 1
                ) AS snippet2,
                (
                    SELECT format_id
                    FROM {$snippetTable} AS snippet
                    WHERE snippet.toc_id = t.id
                    ORDER BY snippet.max_word_pos
                    LIMIT 1 OFFSET 1
                ) AS snippet2_format_id
            FROM relevance_info
            JOIN {$tocTable} AS t ON t.id = relevance_info.toc_id
            JOIN {$metadataTable} AS metadata ON metadata.toc_id = t.id
            {$instanceWhere}
            ORDER BY relevance DESC
            LIMIT :limit
            SQL;

        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof \PDOStatement) {
                throw new UnknownException('Unable to prepare the SQLite recommendations query.');
            }

            $statement->bindValue('external_id', $externalId->getId(), \PDO::PARAM_STR);
            $statement->bindValue('instance_id', (int)$externalId->getInstanceId(), \PDO::PARAM_INT);
            $statement->bindValue('min_common_words', $minCommonWords, \PDO::PARAM_INT);
            $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
            if ($instanceId !== null) {
                $statement->bindValue('candidate_instance_id', $instanceId, \PDO::PARAM_INT);
            }

            $statement->execute();

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            throw new UnknownException('Unable to fetch similar items from SQLite: ' . $exception->getMessage(), 0, $exception);
        }

        return array_map(
            fn(array $row): array => $this->hydrateRecommendation($row, $includeFormatting),
            $rows,
        );
    }

    private function registerSqliteMathFunctions(): void
    {
        $registerFunction = $this->pdo->sqliteCreateFunction(...);
        $registerFunction(
            'register_recommendation_exp',
            static fn(mixed $value): float => exp((float)$value),
            1,
        );
        $registerFunction(
            'register_recommendation_pow',
            static fn(mixed $base, mixed $exponent): float => $base > 0 ? ((float)$base) ** (float)$exponent : 0.0,
            2,
        );
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
