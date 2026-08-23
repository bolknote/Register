<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search;

use Codeception\Test\Unit;
use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Module\Search\Service\SearchDocumentFactory;
use Register\Rose\Entity\Indexable;

final class SearchDocumentFactoryTest extends Unit
{
    public function testMapsUnifiedContentToCanonicalSearchDocuments(): void
    {
        $factory = new SearchDocumentFactory();
        $page    = $factory->create(new ContentItem(
            ContentId::page(7),
            'Page',
            'Text',
            '/section/page',
            123,
            'one, two',
            'Description',
        ));
        $post = $factory->create(new ContentItem(
            ContentId::post(9),
            'Post',
            'Body',
            '/post',
            null,
        ));

        self::assertSame(':page:7', $page->getExternalId()->toString());
        $legacyDocument = (new Indexable('page:7', 'Page', 'Text'))
            ->setKeywords('one, two')
            ->setDescription('Description')
        ;
        self::assertNotSame($legacyDocument->calcHash(), $page->calcHash());
        self::assertSame('/section/page', $page->getUrl());
        self::assertSame(123, $page->getDate()?->getTimestamp());
        self::assertSame(':post:9', $post->getExternalId()->toString());
        self::assertSame('/post', $post->getUrl());
        self::assertSame(1.0, $post->getRelevanceRatio());

        $featuredContent = new ContentItem(
            ContentId::post(10),
            'Featured post',
            'Body',
            '/featured-post',
            456,
            featured: true,
        );
        $featured = $factory->create($featuredContent);
        $unfeaturedTwin = $factory->create(new ContentItem(
            $featuredContent->id,
            $featuredContent->title,
            $featuredContent->body,
            $featuredContent->path,
            $featuredContent->publishedAt,
        ));
        self::assertSame(1.25, $featured->getRelevanceRatio());
        self::assertNotSame($unfeaturedTwin->calcHash(), $featured->calcHash());
    }
}
