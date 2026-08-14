<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Admin;

use Register\Module\Search\Service\BulkIndexingProviderInterface;
use Register\Module\Search\Service\ContentIndexer;
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
            $pendingIds = $this->pendingIds();
            $expected   = [];
            $mismatched = 0;
            $uncovered  = 0;

            foreach ($this->indexingProvider->getIndexables() as $indexable) {
                $externalId            = $indexable->getExternalId()->getId();
                $expected[$externalId] = true;
                $tocEntry              = $this->pdoStorage->getTocByExternalId($indexable->getExternalId());
                if ($tocEntry instanceof TocEntry && $this->matches($indexable, $tocEntry)) {
                    continue;
                }

                ++$mismatched;
                if (!isset($pendingIds[$externalId])) {
                    ++$uncovered;
                }
            }

            $indexedDocuments = $this->pdoStorage->getTocSize(null);
            $expectedDocuments = \count($expected);
            $extraDocuments    = max(0, $indexedDocuments - $expectedDocuments);
            $pendingRemovals   = \count(array_diff_key($pendingIds, $expected));
            $repairRequired    = $uncovered > 0 || $extraDocuments > $pendingRemovals;

            return new SearchIndexHealthStatus(
                true,
                $expectedDocuments,
                $indexedDocuments,
                \count($pendingIds),
                $mismatched + $extraDocuments,
                $repairRequired,
            );
        } catch (\Throwable) {
            return new SearchIndexHealthStatus(false, 0, 0, 0, 0, true);
        }
    }

    /** @return array<string, true> */
    private function pendingIds(): array
    {
        $result = $this->dbLayer
            ->select('id')
            ->from('queue')
            ->where('code = :code')->setParameter('code', ContentIndexer::QUEUE_CODE)
            ->execute()
        ;

        $ids = [];
        while (($row = $result->fetchRow()) !== false) {
            $ids[(string)$row[0]] = true;
        }

        return $ids;
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
