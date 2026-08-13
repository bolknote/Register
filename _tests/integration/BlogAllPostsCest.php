<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Response;

final class BlogAllPostsCest
{
    public function testCanonicalUrlHasTrailingSlash(\IntegrationTester $I): void
    {
        $I->amOnPage('/all');
        $I->seeResponseCodeIs(Response::HTTP_MOVED_PERMANENTLY);
        $I->seeLocationIs('/all/');
    }

    public function testListsOnlyPublishedPostsFromNewestToOldest(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);

        $this->insertPost($dbLayer, 'Older post', 'older-post', 1_700_000_001, true);
        $this->insertPost($dbLayer, 'Newest post', 'newest-post', 1_700_000_003, true);
        $this->insertPost($dbLayer, 'Unpublished post', 'unpublished-post', 1_700_000_004, false);

        $I->amOnPage('/all/');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->see('2 posts', '.blog-all-posts-title');
        $I->assertSame(
            ['Newest post', 'Older post'],
            $I->grabMultiple('.blog-all-posts-list a'),
        );
        $I->assertSame('/newest-post', $I->grabAttributeFrom('.blog-all-posts-list p:first-child a', 'href'));
        $I->dontSee('Unpublished post', '.blog-all-posts');
    }

    public function testPostIsPublishedAtTheSiteRoot(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Root permalink', 'root-permalink', 1_700_000_005, true);

        $I->amOnPage('/root-permalink');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->see('Root permalink', '.post.head');

        $configuredPrefix = $dbLayer
            ->select('COUNT(*)')
            ->from('config')
            ->where("name = 'S2_BLOG_URL'")
            ->execute()
            ->result()
        ;
        $I->assertSame(0, (int)$configuredPrefix);
    }

    private function insertPost(
        DbLayer $dbLayer,
        string $title,
        string $url,
        int $timestamp,
        bool $published,
    ): void {
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::POST->value)
            ->setValue('created_at', ':time')->setParameter('time', $timestamp)
            ->setValue('published_at', ':time')
            ->setValue('updated_at', ':time')
            ->setValue('revision', '1')
            ->setValue('title', ':title')->setParameter('title', $title)
            ->setValue('excerpt', "''")
            ->setValue('body', "'<p>Text</p>'")
            ->setValue('published', $published ? '1' : '0')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('series', "''")
            ->setValue('slug', ':url')->setParameter('url', $url)
            ->setValue('author_id', 'NULL')
            ->execute()
        ;
    }
}
