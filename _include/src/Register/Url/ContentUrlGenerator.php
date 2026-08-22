<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;
use Register\Core\Pdo\QueryBuilder\UnionAll;

/** Generates every canonical public URL for Register content. */
final readonly class ContentUrlGenerator
{
    public function __construct(
        private DbLayer    $dbLayer,
        private UrlBuilder $urlBuilder,
    ) {
    }

    public function postPath(string $slug): string
    {
        $segments = explode('/', $slug);
        if ($slug === '' || in_array('', $segments, true)) {
            throw new \InvalidArgumentException('A post URL path cannot contain empty segments.');
        }

        return '/' . implode('/', array_map(rawurlencode(...), $segments));
    }

    public function post(string $slug): string
    {
        return $this->urlBuilder->link($this->postPath($slug));
    }

    public function absolutePost(string $slug): string
    {
        return $this->urlBuilder->absLink($this->postPath($slug));
    }

    /**
     * @param list<string> $segments Decoded page slugs, excluding the empty main-page slug.
     */
    public function pagePathFromSegments(array $segments, bool $hasChildren): string
    {
        if ($segments === []) {
            return '/';
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException('A nested page URL segment cannot be empty.');
            }
        }

        $path = '/' . implode('/', array_map(rawurlencode(...), $segments));

        return $hasChildren ? $path . '/' : $path;
    }

    /** @throws DbLayerException */
    public function path(ContentId $contentId, bool $publishedOnly = false): ?string
    {
        if ($contentId->type === ContentType::PAGE) {
            return $this->pagePath($contentId->value, $publishedOnly);
        }

        $query = $this->dbLayer
            ->select('slug')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $contentId->value)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
        ;
        if ($publishedOnly) {
            $query->andWhere('published = 1');
        }

        $slug = $query->execute()->result();

        return \is_string($slug) ? $this->postPath($slug) : null;
    }

    /** @throws DbLayerException */
    public function pagePath(int $pageId, bool $publishedOnly = false): ?string
    {
        if ($pageId <= 0) {
            return null;
        }

        $publishedChildQuery = $this->dbLayer
            ->select('1')
            ->from(ContentSchema::TABLE_NAME . ' AS child')
            ->where('child.parent_id = page.id')
            ->andWhere("child.content_type = '" . ContentType::PAGE->value . "'")
        ;
        if ($publishedOnly) {
            $publishedChildQuery->andWhere('child.published = 1');
        }

        $publishedChildQuery->limit(1);

        $baseQuery = $this->dbLayer
            ->select('page.id, page.slug, page.parent_id, 1 AS level')
            ->addSelect('CASE WHEN EXISTS (' . $publishedChildQuery->getSql() . ') THEN 1 ELSE 0 END AS has_children')
            ->from(ContentSchema::TABLE_NAME . ' AS page')
            ->where('page.id = :id')
            ->andWhere("page.content_type = '" . ContentType::PAGE->value . "'")
        ;
        $recursiveQuery = $this->dbLayer
            ->select('page.id, page.slug, page.parent_id, path.level + 1, 0 AS has_children')
            ->from(ContentSchema::TABLE_NAME . ' AS page')
            ->innerJoin('content_path AS path', 'page.id = path.parent_id')
            ->where("page.content_type = '" . ContentType::PAGE->value . "'")
        ;
        if ($publishedOnly) {
            $baseQuery->andWhere('page.published = 1');
            $recursiveQuery->andWhere('page.published = 1');
        }

        $result = $this->dbLayer
            ->withRecursive('content_path', new UnionAll($baseQuery, $recursiveQuery))
            ->select('slug, parent_id, has_children')
            ->from('content_path')
            ->orderBy('level DESC')
            ->setParameter('id', $pageId)
            ->execute()
        ;

        $segments    = [];
        $rootFound   = false;
        $hasChildren = false;
        while ($row = $result->fetchAssoc()) {
            $slug = (string)$row['slug'];
            if ($slug !== '') {
                $segments[] = $slug;
            }

            if ($row['parent_id'] === null) {
                $rootFound = true;
            }

            if ((int)$row['has_children'] === 1) {
                $hasChildren = true;
            }
        }

        return $rootFound ? $this->pagePathFromSegments($segments, $hasChildren) : null;
    }

    /**
     * Completes several already encoded page path tails with their published ancestor paths.
     * Array keys are preserved; entries below an unpublished ancestor are omitted.
     *
     * @param array<mixed> $parentIds
     * @param array<mixed> $pathTails
     * @return array<mixed>
     * @throws DbLayerException
     */
    public function completePublishedPagePaths(array $parentIds, array $pathTails): array
    {
        while ($parentIds !== []) {
            $parentFound = array_fill_keys(array_keys($parentIds), false);
            $idsToSelect = array_values(array_unique($parentIds));
            $result      = $this->dbLayer
                ->select('id, parent_id, slug')
                ->from(ContentSchema::TABLE_NAME)
                ->where('id IN (' . implode(', ', array_fill(0, \count($idsToSelect), '?')) . ')')
                ->andWhere("content_type = '" . ContentType::PAGE->value . "'")
                ->andWhere('published = 1')
                ->execute($idsToSelect)
            ;

            while ($parent = $result->fetchAssoc()) {
                foreach ($parentIds as $key => $parentId) {
                    if ($parentId !== $parent['id'] || $parentFound[$key]) {
                        continue;
                    }

                    $parentIds[$key]   = $parent['parent_id'];
                    $pathTails[$key]   = rawurlencode((string)$parent['slug']) . '/' . $pathTails[$key];
                    $parentFound[$key] = true;
                    if ($parent['parent_id'] === null) {
                        unset($parentIds[$key]);
                    }
                }
            }

            foreach ($parentFound as $key => $found) {
                if (!$found) {
                    unset($pathTails[$key], $parentIds[$key]);
                }
            }
        }

        return $pathTails;
    }

    public function linkPath(string $path): string
    {
        return $this->urlBuilder->link($path);
    }

    public function absolutePath(string $path): string
    {
        return $this->urlBuilder->absLink($path);
    }

    /** @param array<mixed> $params */
    public function rawAbsolutePath(string $path, array $params = []): string
    {
        return $this->urlBuilder->rawAbsLink($path, $params);
    }
}
