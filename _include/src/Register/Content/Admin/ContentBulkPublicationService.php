<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Admin;

use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlCollisionException;
use Register\Core\Framework\Exception\AccessDeniedException;
use Register\Core\Model\PermissionChecker;
use Register\Core\Pdo\DbLayer;

final readonly class ContentBulkPublicationService
{
    public function __construct(
        private DbLayer                 $dbLayer,
        private PermissionChecker       $permissionChecker,
        private ContentSlugService      $contentSlugService,
        private ContentChangeDispatcher $contentChangeDispatcher,
    ) {
    }

    /**
     * @param list<int> $contentIds
     */
    public function setPublished(ContentType $contentType, array $contentIds, bool $published): int
    {
        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_CREATE_ARTICLES)) {
            throw new AccessDeniedException('You do not have enough permissions to perform this action.');
        }

        $contentIds = $this->normalizeIds($contentIds);
        $rows       = $this->loadWritableRows($contentType, $contentIds);
        if (\count($rows) !== \count($contentIds)) {
            throw new AccessDeniedException('One or more selected items are unavailable or cannot be edited.');
        }

        $changedRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (bool)$row['published'] !== $published,
        ));
        if ($changedRows === []) {
            return 0;
        }

        if ($published) {
            foreach ($changedRows as $row) {
                $this->assertPublishable($contentType, $row);
            }
        }

        $changedIds = array_map(static fn(array $row): int => (int)$row['id'], $changedRows);
        $query      = $this->dbLayer
            ->update(ContentSchema::TABLE_NAME)
            ->set('published', ':published')->setParameter('published', $published ? 1 : 0)
            ->set('scheduled_at', '0')
            ->where('content_type = :content_type')->setParameter('content_type', $contentType->value)
        ;
        if ($published) {
            $query
                ->set(
                    'published_at',
                    'CASE WHEN published_at IS NULL OR published_at = 0 THEN :published_at ELSE published_at END',
                )
                ->setParameter('published_at', time())
            ;
        }

        $placeholders = [];
        foreach ($changedIds as $index => $contentId) {
            $placeholder   = 'content_id_' . $index;
            $placeholders[] = ':' . $placeholder;
            $query->setParameter($placeholder, $contentId);
        }

        $updated = $query
            ->andWhere('id IN (' . implode(', ', $placeholders) . ')')
            ->execute()
            ->affectedRows()
        ;

        if ($updated !== \count($changedIds)) {
            throw new \RuntimeException('The selected content changed while the bulk action was running.');
        }

        $changedContentIds = [];
        foreach ($changedIds as $contentId) {
            if ($contentType === ContentType::PAGE) {
                array_push($changedContentIds, ...$this->contentChangeDispatcher->pageBranch($contentId));
            } else {
                $changedContentIds[] = ContentId::post($contentId);
            }
        }

        $this->contentChangeDispatcher->dispatch(...$changedContentIds);

        return $updated;
    }

    /**
     * @param list<int> $contentIds
     * @return list<array<string, mixed>>
     */
    private function loadWritableRows(ContentType $contentType, array $contentIds): array
    {
        $query = $this->dbLayer
            ->select('id, title, slug, published')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentType->value)
        ;

        $placeholders = [];
        foreach ($contentIds as $index => $contentId) {
            $placeholder   = 'selected_id_' . $index;
            $placeholders[] = ':' . $placeholder;
            $query->setParameter($placeholder, $contentId);
        }

        $query->andWhere('id IN (' . implode(', ', $placeholders) . ')');

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)) {
            $userId = $this->permissionChecker->getUserId();
            if ($userId === null) {
                throw new AccessDeniedException('No authenticated user found.');
            }

            $query->andWhere('author_id = :author_id')->setParameter('author_id', $userId);
        }

        $result = $query->execute();
        $rows   = [];
        while (($row = $result->fetchAssoc()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function assertPublishable(ContentType $contentType, array $row): void
    {
        $contentId = (int)$row['id'];
        $status    = $contentType === ContentType::POST
            ? $this->contentSlugService->postStatus($contentId, (string)$row['slug'])
            : $this->contentSlugService->pageStatus($contentId, (string)$row['slug']);
        $allowedStatuses = $contentType === ContentType::PAGE
            ? [ContentSlugService::STATUS_OK, ContentSlugService::STATUS_MAIN_PAGE]
            : [ContentSlugService::STATUS_OK];
        if (!\in_array($status, $allowedStatuses, true)) {
            throw new ContentUrlCollisionException(sprintf(
                'Cannot publish “%s”: its URL is unavailable.',
                (string)$row['title'],
            ));
        }
    }

    /**
     * @param list<int> $contentIds
     * @return non-empty-list<int>
     */
    private function normalizeIds(array $contentIds): array
    {
        $contentIds = array_values(array_unique($contentIds));
        if ($contentIds === [] || \count($contentIds) > 50) {
            throw new \InvalidArgumentException('Select between 1 and 50 items.');
        }

        foreach ($contentIds as $contentId) {
            if ($contentId <= 0) {
                throw new \InvalidArgumentException('Invalid selected item identifier.');
            }
        }

        return $contentIds;
    }
}
