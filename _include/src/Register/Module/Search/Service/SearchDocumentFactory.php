<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use Register\Content\ContentId;
use Register\Content\ContentItem;
/** Maps Register content to the current Rose search-index format. */
final class SearchDocumentFactory
{
    public function create(ContentItem $content): SearchIndexable
    {
        $publishedAt = $content->publishedAt === null
            ? null
            : (new \DateTime())->setTimestamp($content->publishedAt);

        $indexable = new SearchIndexable(
            self::externalId($content->id),
            $content->title,
            $content->body,
        );
        $indexable
            ->setKeywords($content->keywords)
            ->setDescription($content->description)
            ->setDate($publishedAt)
            ->setUrl($content->path)
        ;

        return $indexable;
    }

    /** Returns the canonical storage-independent identifier used by the search index. */
    public static function externalId(ContentId $id): string
    {
        return (string)$id;
    }
}
