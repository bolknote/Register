<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Live\LiveUpdateRepository;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Pdo\DbLayer;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Publishes content lifecycle changes and keeps deletion notifications until the write completes.
 */
final class ContentChangeDispatcher implements StatefulServiceInterface
{
    /** @var array<string, ContentId> */
    private array $deferred = [];

    public function __construct(
        private readonly DbLayer                  $dbLayer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LiveUpdateRepository     $liveUpdateRepository,
    ) {
    }

    public function dispatch(ContentId ...$contentIds): void
    {
        foreach ($this->unique($contentIds) as $contentId) {
            $this->liveUpdateRepository->publishContent($contentId);
            $this->eventDispatcher->dispatch(new ContentChangedEvent($contentId));
        }
    }

    public function dispatchPageBranch(int $rootId): void
    {
        $this->dispatch(...$this->pageBranch($rootId));
    }

    /**
     * Captures a page branch before a move or deletion changes its database rows.
     *
     * @return list<ContentId>
     */
    public function pageBranch(int $rootId): array
    {
        $result = $this->dbLayer
            ->select('id, parent_id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;

        /** @var array<int, list<int>> $children */
        $children = [];
        $knownIds = [];
        while (($row = $result->fetchAssoc()) !== false) {
            $id            = (int)$row['id'];
            $knownIds[$id] = true;
            if ($row['parent_id'] !== null) {
                $children[(int)$row['parent_id']][] = $id;
            }
        }

        if (!isset($knownIds[$rootId])) {
            return [];
        }

        $contentIds = [];
        $pending    = [$rootId];
        while (($id = array_shift($pending)) !== null) {
            $contentIds[] = ContentId::page($id);
            array_push($pending, ...($children[$id] ?? []));
        }

        return $contentIds;
    }

    /**
     * Defers notifications whose database write has not completed yet, notably AdminYard deletes.
     */
    public function defer(ContentId ...$contentIds): void
    {
        foreach ($contentIds as $contentId) {
            $this->deferred[(string)$contentId] = $contentId;
        }
    }

    public function flush(): void
    {
        $deferred       = array_values($this->deferred);
        $this->deferred = [];
        $this->dispatch(...$deferred);
    }

    #[\Override]
    public function clearState(): void
    {
        $this->deferred = [];
    }

    /**
     * @param ContentId[] $contentIds
     * @return list<ContentId>
     */
    private function unique(array $contentIds): array
    {
        $unique = [];
        foreach ($contentIds as $contentId) {
            $unique[(string)$contentId] = $contentId;
        }

        return array_values($unique);
    }
}
