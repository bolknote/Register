<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Content;

use Codeception\Test\Unit;
use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentSourceInterface;
use Register\Content\ContentType;
use Register\Content\RecentContentSourceInterface;

final class ContentRepositoryTest extends Unit
{
    public function testDelegatesLookupAndCombinesPublishedContent(): void
    {
        $page = new ContentItem(ContentId::page(1), 'Page', 'Page body', '/page', null);
        $post = new ContentItem(ContentId::post(2), 'Post', 'Post body', '/post', 123);
        $repository = new ContentRepository(
            new InMemoryContentSource(ContentType::PAGE, $page),
            new InMemoryRecentContentSource(ContentType::POST, $post),
        );

        self::assertSame($page, $repository->find(ContentId::page(1)));
        self::assertSame($post, $repository->find(ContentId::post(2)));
        self::assertSame([$page, $post], iterator_to_array($repository->published(), false));
        self::assertSame([$post], iterator_to_array($repository->published(ContentType::POST), false));
        self::assertSame([$post], iterator_to_array($repository->recent(ContentType::POST, 1), false));
    }

    public function testCommentCapabilityBelongsToCanonicalContentItem(): void
    {
        $page = new ContentItem(ContentId::page(1), 'Page', 'Body', '/page', null, commentsEnabled: false);

        self::assertFalse($page->commentsEnabled);
    }

    public function testRejectsDuplicateSources(): void
    {
        $this->expectException(\LogicException::class);
        new ContentRepository(
            new InMemoryContentSource(ContentType::POST),
            new InMemoryContentSource(ContentType::POST),
        );
    }
}

/** @internal */
final readonly class InMemoryContentSource implements ContentSourceInterface
{
    public function __construct(
        private ContentType  $contentType,
        private ?ContentItem $item = null,
    ) {
    }

    #[\Override]
    public function type(): ContentType
    {
        return $this->contentType;
    }

    #[\Override]
    public function find(ContentId $id): ?ContentItem
    {
        return $this->item?->id->equals($id) === true ? $this->item : null;
    }

    #[\Override]
    public function published(): iterable
    {
        if ($this->item instanceof ContentItem) {
            yield $this->item;
        }
    }
}

/** @internal */
final readonly class InMemoryRecentContentSource implements RecentContentSourceInterface
{
    public function __construct(
        private ContentType  $contentType,
        private ?ContentItem $item = null,
    ) {
    }

    #[\Override]
    public function type(): ContentType
    {
        return $this->contentType;
    }

    #[\Override]
    public function find(ContentId $id): ?ContentItem
    {
        return $this->item?->id->equals($id) === true ? $this->item : null;
    }

    #[\Override]
    public function published(): iterable
    {
        if ($this->item instanceof ContentItem) {
            yield $this->item;
        }
    }

    #[\Override]
    public function recent(int $limit): iterable
    {
        if ($limit > 0 && $this->item instanceof ContentItem) {
            yield $this->item;
        }
    }
}
