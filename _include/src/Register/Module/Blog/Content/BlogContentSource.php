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
use Register\Content\ContentSourceInterface;
use Register\Content\ContentType;
use Register\Module\Blog\BlogUrlBuilder;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

/** Adapts the inherited post table to Register's content contract. */
final readonly class BlogContentSource implements ContentSourceInterface
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
            ->select('id, title, text, create_time, url')
            ->from('s2_blog_posts')
            ->where('id = :id')->setParameter('id', $id->value)
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
            ->select('id, title, text, create_time, url')
            ->from('s2_blog_posts')
            ->where('published = 1')
            ->orderBy('id')
            ->execute()
        ;

        while ($post = $result->fetchAssoc()) {
            yield $this->fromRow($post);
        }
    }

    /** @param array<string, mixed> $post */
    private function fromRow(array $post): ContentItem
    {
        $timestamp = (int)$post['create_time'];

        return new ContentItem(
            id: ContentId::post((int)$post['id']),
            title: (string)$post['title'],
            body: (string)$post['text'],
            path: $this->urlBuilder->postWithoutPrefix((string)$post['url']),
            publishedAt: $timestamp > 0 ? $timestamp : null,
        );
    }
}
