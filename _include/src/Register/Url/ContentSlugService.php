<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

/** Generates canonical slugs and checks the shared post/page URL namespace. */
final readonly class ContentSlugService
{
    public const string STATUS_EMPTY = 'empty';

    public const string STATUS_MAIN_PAGE = 'mainpage';

    public const string STATUS_MISSING = 'missing';

    public const string STATUS_NOT_UNIQUE = 'not_unique';

    public const string STATUS_OK = 'ok';

    public const string STATUS_UNAVAILABLE = 'unavailable';

    private const string CANONICAL_SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public function __construct(
        private DbLayer               $dbLayer,
        private UniqueSlugGenerator   $uniqueSlugGenerator,
        private ReservedRouteRegistry $reservedRouteRegistry,
    ) {
    }

    /** @throws DbLayerException */
    public function generatePost(string $title): string
    {
        return $this->uniqueSlugGenerator->generate(
            $title,
            fn(string $slug): bool => $this->postStatus(0, $slug) === self::STATUS_OK,
            ContentType::POST->value,
        );
    }

    /** @throws DbLayerException */
    public function generatePage(int $parentId, string $title): string
    {
        return $this->uniqueSlugGenerator->generate(
            $title,
            fn(string $slug): bool => $this->pageStatusAtParent(0, $parentId, $slug) === self::STATUS_OK,
            ContentType::PAGE->value,
        );
    }

    /** @throws DbLayerException */
    public function postStatus(int $postId, string $slug): string
    {
        $syntaxStatus = $this->syntaxStatus($slug);
        if ($syntaxStatus !== self::STATUS_OK || $this->reservedRouteRegistry->contains($slug)) {
            return $syntaxStatus === self::STATUS_OK ? self::STATUS_UNAVAILABLE : $syntaxStatus;
        }

        return $this->statusInScope($postId, 'root', $slug);
    }

    /** @throws DbLayerException */
    public function pageStatus(int $pageId, ?string $slug = null): string
    {
        $page = $this->dbLayer
            ->select('parent_id, slug_scope, slug')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $pageId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
            ->fetchAssoc()
        ;

        if ($page === false) {
            return self::STATUS_MISSING;
        }

        $parentId = $page['parent_id'];
        if ($parentId === null) {
            return self::STATUS_MAIN_PAGE;
        }

        return $this->pageStatusInScope($pageId, (string)$page['slug_scope'], $slug ?? (string)$page['slug']);
    }

    /** @throws DbLayerException */
    public function pageStatusAtParent(int $pageId, int $parentId, string $slug): string
    {
        $syntaxStatus = $this->syntaxStatus($slug);
        if ($syntaxStatus !== self::STATUS_OK) {
            return $syntaxStatus;
        }

        return $this->pageStatusInScope($pageId, $this->pageScope($parentId), $slug);
    }

    /** @throws DbLayerException */
    public function pageScope(int $parentId): string
    {
        $parentIsRoot = $this->isRootPage($parentId);
        if ($parentIsRoot === null) {
            throw new \InvalidArgumentException(\sprintf('Page parent %d does not exist.', $parentId));
        }

        return $parentIsRoot ? 'root' : 'page:' . $parentId;
    }

    private function syntaxStatus(string $slug): string
    {
        if ($slug === '') {
            return self::STATUS_EMPTY;
        }

        return preg_match(self::CANONICAL_SLUG_PATTERN, $slug) === 1
            ? self::STATUS_OK
            : self::STATUS_UNAVAILABLE;
    }

    /** @throws DbLayerException */
    private function isRootPage(int $pageId): ?bool
    {
        $page = $this->dbLayer
            ->select('parent_id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $pageId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
            ->fetchAssoc()
        ;

        return $page === false ? null : $page['parent_id'] === null;
    }

    /** @throws DbLayerException */
    private function pageStatusInScope(int $pageId, string $scope, string $slug): string
    {
        $syntaxStatus = $this->syntaxStatus($slug);
        if ($syntaxStatus !== self::STATUS_OK) {
            return $syntaxStatus;
        }

        if ($scope === 'root' && $this->reservedRouteRegistry->contains($slug)) {
            return self::STATUS_UNAVAILABLE;
        }

        return $this->statusInScope($pageId, $scope, $slug);
    }

    /** @throws DbLayerException */
    private function statusInScope(int $contentId, string $scope, string $slug): string
    {
        $collisionCount = $this->dbLayer
            ->select('COUNT(*)')
            ->from(ContentSchema::TABLE_NAME)
            ->where('slug_scope = :slug_scope')->setParameter('slug_scope', $scope)
            ->andWhere('slug = :slug')->setParameter('slug', $slug)
            ->andWhere('id <> :id')->setParameter('id', $contentId)
            ->execute()
            ->result()
        ;

        return (int)$collisionCount > 0 ? self::STATUS_NOT_UNIQUE : self::STATUS_OK;
    }
}
