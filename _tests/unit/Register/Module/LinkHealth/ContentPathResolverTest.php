<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentSourceInterface;
use Register\Content\ContentType;
use Register\Module\LinkHealth\ContentPathResolver;
use Register\Module\LinkHealth\LinkUrlNormalizer;

final class ContentPathResolverTest extends Unit
{
    public function testDoesNotResolveAnAmbiguousExactPathThroughItsSlashAlias(): void
    {
        $pageSource = $this->source(ContentType::PAGE, [
            $this->item(ContentId::page(1), '/ambiguous'),
            $this->item(ContentId::page(2), '/ambiguous/'),
            $this->item(ContentId::page(3), '/aliased'),
        ]);
        $postSource = $this->source(ContentType::POST, [
            $this->item(ContentId::post(4), '/ambiguous'),
        ]);
        $resolver = new ContentPathResolver(
            new ContentRepository($pageSource, $postSource),
            new LinkUrlNormalizer('https://example.test', ''),
        );

        self::assertNull($resolver->resolve('/ambiguous'));
        self::assertSame('page:2', (string)$resolver->resolve('/ambiguous/'));
        self::assertSame('page:3', (string)$resolver->resolve('/aliased/'));
    }

    /** @param list<ContentItem> $items */
    private function source(ContentType $type, array $items): ContentSourceInterface
    {
        return new ContentPathResolverSource($type, $items);
    }

    private function item(ContentId $id, string $path): ContentItem
    {
        return new ContentItem($id, 'Title', '', $path, 1_700_000_000);
    }
}

/** @internal */
final readonly class ContentPathResolverSource implements ContentSourceInterface
{
    /** @param list<ContentItem> $items */
    public function __construct(
        private ContentType $contentType,
        private array       $items,
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
        foreach ($this->items as $item) {
            if ($item->id->equals($id)) {
                return $item;
            }
        }

        return null;
    }

    /** @return iterable<ContentItem> */
    #[\Override]
    public function published(): iterable
    {
        return $this->items;
    }
}
