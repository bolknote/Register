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

/** Exposes published pages through Register's shared content contract. */
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
            ->from(ContentSchema::TABLE_NAME . ' AS child')
            ->where('child.parent_id = page.id')
            ->andWhere("child.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('child.published = 1')
            ->limit(1)
            ->getSql()
        ;

        $page = $this->dbLayer
            ->select('page.id, page.parent_id, page.title, page.body, page.slug')
            ->addSelect('page.published_at, page.meta_keywords, page.meta_description')
            ->addSelect('(' . $childrenQuery . ') IS NOT NULL AS has_children')
            ->from(ContentSchema::TABLE_NAME . ' AS page')
            ->where('page.id = :id')->setParameter('id', $id->value)
            ->andWhere("page.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('page.published = 1')
            ->execute()
            ->fetchAssoc()
        ;
        if ($page === false) {
            return null;
        }

        $parentPath = $this->articleProvider->pathFromId(
            $page['parent_id'] === null ? ArticleProvider::ROOT_ID : (int)$page['parent_id'],
            true,
        );
        if ($parentPath === null) {
            return null;
        }

        $slug = (string)$page['slug'];
        $path = rtrim($parentPath, '/') . '/' . rawurlencode($slug);
        if ($slug !== '' && (bool)$page['has_children']) {
            $path .= '/';
        }

        $timestamp = $page['published_at'] === null ? 0 : (int)$page['published_at'];

        return new ContentItem(
            id: $id,
            title: (string)$page['title'],
            body: (string)$page['body'],
            path: $path,
            publishedAt: $timestamp > 0 ? $timestamp : null,
            keywords: (string)$page['meta_keywords'],
            description: (string)$page['meta_description'],
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
            ->from(ContentSchema::TABLE_NAME . ' AS child')
            ->where('child.parent_id = page.id')
            ->andWhere("child.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('child.published = 1')
            ->limit(1)
            ->getSql()
        ;

        $query = $this->dbLayer
            ->select('page.id, page.title, page.body, page.slug, page.published_at')
            ->addSelect('page.meta_keywords, page.meta_description')
            ->addSelect('(' . $childrenQuery . ') IS NOT NULL AS has_children')
            ->from(ContentSchema::TABLE_NAME . ' AS page')
            ->where("page.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('page.published = 1')
            ->orderBy('page.id')
        ;

        if ($parentId === ArticleProvider::ROOT_ID) {
            $query->andWhere('page.parent_id IS NULL');
        } else {
            $query->andWhere('page.parent_id = :parent_id')->setParameter('parent_id', $parentId);
        }

        $result = $query->execute();

        while ($page = $result->fetchAssoc()) {
            $slug = (string)$page['slug'];
            $path = rtrim($parentPath, '/') . '/' . rawurlencode($slug);
            if ($slug !== '' && (bool)$page['has_children']) {
                $path .= '/';
            }

            $timestamp = $page['published_at'] === null ? 0 : (int)$page['published_at'];
            yield new ContentItem(
                id: ContentId::page((int)$page['id']),
                title: (string)$page['title'],
                body: (string)$page['body'],
                path: $path,
                publishedAt: $timestamp > 0 ? $timestamp : null,
                keywords: (string)$page['meta_keywords'],
                description: (string)$page['meta_description'],
            );

            yield from $this->crawl((int)$page['id'], rtrim($path, '/') . '/');
        }
    }
}
