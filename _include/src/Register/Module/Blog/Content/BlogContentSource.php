<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Content;

use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\RecentContentSourceInterface;
use Register\Module\Blog\BlogUrlBuilder;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

/** Exposes published posts from Register's shared content storage. */
final readonly class BlogContentSource implements RecentContentSourceInterface
{
    public function __construct(
        private DbLayer        $dbLayer,
        private BlogUrlBuilder $urlBuilder,
    ) {
    }

    #[\Override]
    public function type(): ContentType
    {
        return ContentType::POST;
    }

    /** @throws DbLayerException */
    #[\Override]
    public function find(ContentId $id): ?ContentItem
    {
        if ($id->type !== $this->type()) {
            return null;
        }

        $post = $this->dbLayer
            ->select(...$this->selectExpressions())
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $id->value)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published = 1')
            ->execute()
            ->fetchAssoc()
        ;

        return $post === false ? null : $this->fromRow($post);
    }

    /**
     * @throws DbLayerException
     * @return \Generator<int, ContentItem>
     */
    #[\Override]
    public function published(): \Generator
    {
        $result = $this->dbLayer
            ->select(...$this->selectExpressions())
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published = 1')
            ->orderBy('id')
            ->execute()
        ;

        while ($post = $result->fetchAssoc()) {
            yield $this->fromRow($post);
        }
    }

    /**
     * @throws DbLayerException
     * @return \Generator<int, ContentItem>
     */
    #[\Override]
    public function recent(int $limit): \Generator
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The recent content limit must be positive.');
        }

        $result = $this->dbLayer
            ->select(...$this->selectExpressions())
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published = 1')
            ->orderBy('published_at DESC')
            ->limit($limit)
            ->execute()
        ;

        while ($post = $result->fetchAssoc()) {
            yield $this->fromRow($post);
        }
    }

    /** @param array<string, mixed> $post */
    private function fromRow(array $post): ContentItem
    {
        $publishedAt = $post['published_at'] === null ? null : (int)$post['published_at'];

        return new ContentItem(
            id: ContentId::post((int)$post['id']),
            title: (string)$post['title'],
            body: (string)$post['body'],
            path: $this->urlBuilder->postWithoutPrefix((string)$post['slug']),
            publishedAt: $publishedAt !== null && $publishedAt > 0 ? $publishedAt : null,
            updatedAt: (int)$post['updated_at'],
            author: (string)($post['author'] ?? ''),
            series: (string)$post['series'],
            commentsEnabled: (bool)$post['comments_enabled'],
        );
    }

    /** @return list<string> */
    private function selectExpressions(): array
    {
        $authorQuery = $this->dbLayer
            ->select('users.name')
            ->from('users')
            ->where('users.id = author_id')
            ->getSql()
        ;

        return [
            'id',
            'title',
            'body',
            'published_at',
            'updated_at',
            'slug',
            'series',
            'comments_enabled',
            '(' . $authorQuery . ') AS author',
        ];
    }
}
