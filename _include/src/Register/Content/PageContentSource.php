<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Url\ContentUrlGenerator;
use Register\Core\Model\ArticleProvider;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;

/** Exposes published pages through Register's shared content contract. */
final readonly class PageContentSource implements ContentSourceInterface
{
    public function __construct(
        private DbLayer             $dbLayer,
        private ContentUrlGenerator $contentUrlGenerator,
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

        $page = $this->dbLayer
            ->select('page.id, page.title, page.body, page.excerpt, page.comments_enabled, page.featured')
            ->addSelect('page.published_at, page.updated_at, page.meta_keywords, page.meta_description, page.social_image, page.author_id')
            ->addSelect('(' . $this->authorNameQuery() . ') AS author')
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

        $path = $this->contentUrlGenerator->pagePath($id->value, true);
        if ($path === null) {
            return null;
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
            updatedAt: (int)$page['updated_at'],
            author: (string)($page['author'] ?? ''),
            commentsEnabled: (bool)$page['comments_enabled'],
            excerpt: (string)$page['excerpt'],
            authorId: $page['author_id'] === null ? null : (int)$page['author_id'],
            featured: (bool)$page['featured'],
            socialImage: (string)$page['social_image'],
        );
    }

    /**
     * @throws DbLayerException
     * @return \Generator<int, ContentItem>
     */
    #[\Override]
    public function published(): \Generator
    {
        yield from $this->crawl(ArticleProvider::ROOT_ID, []);
    }

    /**
     * @throws DbLayerException
     * @param list<string> $parentSegments
     * @return \Generator<int, ContentItem>
     */
    private function crawl(int $parentId, array $parentSegments): \Generator
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
            ->select('page.id, page.title, page.body, page.excerpt, page.slug, page.published_at, page.updated_at, page.comments_enabled, page.featured')
            ->addSelect('page.meta_keywords, page.meta_description, page.social_image, page.author_id')
            ->addSelect('(' . $this->authorNameQuery() . ') AS author')
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
            $slug     = (string)$page['slug'];
            $segments = $slug === '' ? $parentSegments : [...$parentSegments, $slug];
            $path     = $this->contentUrlGenerator->pagePathFromSegments($segments, (bool)$page['has_children']);

            $timestamp = $page['published_at'] === null ? 0 : (int)$page['published_at'];
            yield new ContentItem(
                id: ContentId::page((int)$page['id']),
                title: (string)$page['title'],
                body: (string)$page['body'],
                path: $path,
                publishedAt: $timestamp > 0 ? $timestamp : null,
                keywords: (string)$page['meta_keywords'],
                description: (string)$page['meta_description'],
                updatedAt: (int)$page['updated_at'],
                author: (string)($page['author'] ?? ''),
                commentsEnabled: (bool)$page['comments_enabled'],
                excerpt: (string)$page['excerpt'],
                authorId: $page['author_id'] === null ? null : (int)$page['author_id'],
                featured: (bool)$page['featured'],
                socialImage: (string)$page['social_image'],
            );

            yield from $this->crawl((int)$page['id'], $segments);
        }
    }

    private function authorNameQuery(): string
    {
        return $this->dbLayer
            ->select('users.name')
            ->from('users')
            ->where('users.id = page.author_id')
            ->getSql()
        ;
    }
}
