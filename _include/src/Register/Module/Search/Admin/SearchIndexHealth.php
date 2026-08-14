<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Admin;

use Register\Module\Search\Service\BulkIndexingProviderInterface;
use Register\Module\Search\Service\ContentIndexer;
use Register\Module\Search\Service\SearchIndexRepairer;
use S2\Cms\Pdo\DbLayer;
use S2\Rose\Entity\Indexable;
use S2\Rose\Entity\TocEntry;
use S2\Rose\Storage\Database\PdoStorage;

/** Compares canonical published content with Rose's table of contents. */
final readonly class SearchIndexHealth
{
    public function __construct(
        private PdoStorage                     $pdoStorage,
        private DbLayer                       $dbLayer,
        private BulkIndexingProviderInterface $indexingProvider,
    ) {
    }

    public function inspect(): SearchIndexHealthStatus
    {
        try {
            $pending = $this->pendingJobs();
            $pendingContent = $pending['content'];
            $pendingRemoval = $pending['removal'];
            $repairActive   = $pending['repair'];
            $indexed        = [];
            foreach ($this->pdoStorage->getTocByTitlePrefix('') as $indexedDocument) {
                $indexed[$indexedDocument->getExternalId()->toString()] = $indexedDocument->getTocEntry();
            }

            $expected          = [];
            $mismatched        = 0;
            $uncoveredExpected = 0;

            foreach ($this->indexingProvider->getIndexables() as $indexable) {
                $externalId            = $indexable->getExternalId()->toString();
                $expected[$externalId] = true;
                $tocEntry              = $indexed[$externalId] ?? null;
                if ($tocEntry instanceof TocEntry && $this->matches($indexable, $tocEntry)) {
                    continue;
                }

                ++$mismatched;
                if (!isset($pendingContent[$externalId])) {
                    ++$uncoveredExpected;
                }
            }

            $extraDocuments  = array_diff_key($indexed, $expected);
            $uncoveredExtras = array_diff_key($extraDocuments, $pendingRemoval);
            $expectedDocuments = \count($expected);
            $pendingUpdates    = \count($pendingContent) + \count($pendingRemoval);
            $repairRequired    = !$repairActive
                && ($uncoveredExpected > 0 || $uncoveredExtras !== []);

            return new SearchIndexHealthStatus(
                true,
                $expectedDocuments,
                \count($indexed),
                $pendingUpdates,
                $mismatched + \count($extraDocuments),
                $repairActive,
                $repairRequired,
            );
        } catch (\Throwable) {
            return new SearchIndexHealthStatus(false, 0, 0, 0, 0, false, true);
        }
    }

    /**
     * @return array{
     *     content: array<string, true>,
     *     removal: array<string, true>,
     *     repair: bool
     * }
     */
    private function pendingJobs(): array
    {
        $result = $this->dbLayer
            ->select('id, code')
            ->from('queue')
            ->where('failed_at IS NULL')
            ->andWhere("code IN ('" . ContentIndexer::QUEUE_CODE . "', '"
                . SearchIndexRepairer::REPAIR_QUEUE_CODE . "', '"
                . SearchIndexRepairer::REMOVE_QUEUE_CODE . "')")
            ->execute()
        ;

        $content = [];
        $removal = [];
        $repair  = false;
        while (($row = $result->fetchAssoc()) !== false) {
            $id   = (string)$row['id'];
            $code = (string)$row['code'];
            if ($code === ContentIndexer::QUEUE_CODE) {
                $content[':' . $id] = true;
            } elseif ($code === SearchIndexRepairer::REMOVE_QUEUE_CODE) {
                $removal[$id] = true;
            } elseif ($code === SearchIndexRepairer::REPAIR_QUEUE_CODE) {
                $repair = true;
            }
        }

        return [
            'content' => $content,
            'removal' => $removal,
            'repair'  => $repair,
        ];
    }

    private function matches(Indexable $indexable, TocEntry $tocEntry): bool
    {
        return hash_equals($indexable->calcHash(), $tocEntry->getHash())
            && $indexable->getTitle() === $tocEntry->getTitle()
            && $indexable->getDescription() === $tocEntry->getDescription()
            && $indexable->getUrl() === $tocEntry->getUrl()
            && $indexable->getRelevanceRatio() === $tocEntry->getRelevanceRatio()
            && $indexable->getDate()?->getTimestamp() === $tocEntry->getDate()?->getTimestamp();
    }
}
