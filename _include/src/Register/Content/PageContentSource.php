<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use S2\Cms\Model\ArticleProvider;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

/** Adapts the inherited page table to Register's content contract. */
final readonly class PageContentSource implements ContentSourceInterface
{
    public function __construct(
        private DbLayer         $dbLayer,
        private ArticleProvider $articleProvider,
    ) {
    }

    #[\Override]
    public function type(): ContentType
    {
        return ContentType::PAGE;
    }

    /** @throws DbLayerException */
    #[\Override]
    public function find(ContentId $id): ?ContentItem
    {
        if ($id->type !== $this->type()) {
            return null;
        }

        $childrenQuery = $this->dbLayer
            ->select('1')
            ->from('articles AS child')
            ->where('child.parent_id = page.id')
            ->andWhere('child.published = 1')
            ->limit(1)
            ->getSql()
        ;

        $page = $this->dbLayer
            ->select('page.id, page.parent_id, page.title, page.pagetext, page.url')
            ->addSelect('page.create_time, page.meta_keys, page.meta_desc')
            ->addSelect('(' . $childrenQuery . ') IS NOT NULL AS has_children')
            ->from('articles AS page')
            ->where('page.id = :id')->setParameter('id', $id->value)
            ->andWhere('page.published = 1')
            ->execute()
            ->fetchAssoc()
        ;
        if ($page === false) {
            return null;
        }

        $parentPath = $this->articleProvider->pathFromId((int)$page['parent_id'], true);
        if ($parentPath === null) {
            return null;
        }

        $slug = (string)$page['url'];
        $path = rtrim($parentPath, '/') . '/' . rawurlencode($slug);
        if ($slug !== '' && (bool)$page['has_children']) {
            $path .= '/';
        }

        $timestamp = (int)$page['create_time'];

        return new ContentItem(
            id: $id,
            title: (string)$page['title'],
            body: (string)$page['pagetext'],
            path: $path,
            publishedAt: $timestamp > 0 ? $timestamp : null,
            keywords: (string)$page['meta_keys'],
            description: (string)$page['meta_desc'],
        );
    }

    /**
     * @throws DbLayerException
     * @return \Generator<int, ContentItem>
     */
    #[\Override]
    public function published(): \Generator
    {
        yield from $this->crawl(ArticleProvider::ROOT_ID, '');
    }

    /**
     * @throws DbLayerException
     * @return \Generator<int, ContentItem>
     */
    private function crawl(int $parentId, string $parentPath): \Generator
    {
        $childrenQuery = $this->dbLayer
            ->select('1')
            ->from('articles AS child')
            ->where('child.parent_id = page.id')
            ->andWhere('child.published = 1')
            ->limit(1)
            ->getSql()
        ;

        $result = $this->dbLayer
            ->select('page.id, page.title, page.pagetext, page.url, page.create_time')
            ->addSelect('page.meta_keys, page.meta_desc')
            ->addSelect('(' . $childrenQuery . ') IS NOT NULL AS has_children')
            ->from('articles AS page')
            ->where('page.parent_id = :parent_id')->setParameter('parent_id', $parentId)
            ->andWhere('page.published = 1')
            ->orderBy('page.id')
            ->execute()
        ;

        while ($page = $result->fetchAssoc()) {
            $slug = (string)$page['url'];
            $path = rtrim($parentPath, '/') . '/' . rawurlencode($slug);
            if ($slug !== '' && (bool)$page['has_children']) {
                $path .= '/';
            }

            $timestamp = (int)$page['create_time'];
            yield new ContentItem(
                id: ContentId::page((int)$page['id']),
                title: (string)$page['title'],
                body: (string)$page['pagetext'],
                path: $path,
                publishedAt: $timestamp > 0 ? $timestamp : null,
                keywords: (string)$page['meta_keys'],
                description: (string)$page['meta_desc'],
            );

            yield from $this->crawl((int)$page['id'], rtrim($path, '/') . '/');
        }
    }
}
