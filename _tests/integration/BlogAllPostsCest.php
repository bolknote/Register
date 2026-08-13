<?php

declare(strict_types = 1);

namespace integration;

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

    private function insertPost(
        DbLayer $dbLayer,
        string $title,
        string $url,
        int $timestamp,
        bool $published,
    ): void {
        $dbLayer
            ->insert('s2_blog_posts')
            ->setValue('create_time', ':time')->setParameter('time', $timestamp)
            ->setValue('modify_time', ':time')
            ->setValue('revision', '1')
            ->setValue('title', ':title')->setParameter('title', $title)
            ->setValue('text', "'<p>Text</p>'")
            ->setValue('published', $published ? '1' : '0')
            ->setValue('favorite', '0')
            ->setValue('commented', '1')
            ->setValue('label', "''")
            ->setValue('url', ':url')->setParameter('url', $url)
            ->setValue('user_id', 'NULL')
            ->execute()
        ;
    }
}
