<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

/** Aggregates mandatory content sources behind one product-level API. */
final readonly class ContentRepository
{
    /** @var array<string, ContentSourceInterface> */
    private array $sources;

    public function __construct(ContentSourceInterface ...$sources)
    {
        $indexedSources = [];
        foreach ($sources as $source) {
            $type = $source->type()->value;
            if (isset($indexedSources[$type])) {
                throw new \LogicException(\sprintf('Content source "%s" is registered more than once.', $type));
            }

            $indexedSources[$type] = $source;
        }

        $this->sources = $indexedSources;
    }

    public function find(ContentId $id): ?ContentItem
    {
        return $this->source($id->type)->find($id);
    }

    /** @return \Generator<int, ContentItem> */
    public function published(): \Generator
    {
        foreach ($this->sources as $source) {
            yield from $source->published();
        }
    }

    private function source(ContentType $type): ContentSourceInterface
    {
        return $this->sources[$type->value]
            ?? throw new \LogicException(\sprintf('Content source "%s" is not registered.', $type->value));
    }
}
