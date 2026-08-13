<?php

declare(strict_types = 1);

namespace integration;

use S2\Cms\Pdo\DbLayer;

class BlogPaginationCest
{
    public function testPaginationAppearsOnlyForMultiplePages(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $I->setConfigValue('S2_MAX_ITEMS', '2');

        $this->insertPost($dbLayer, 1);
        $this->insertPost($dbLayer, 2);
        $this->insertPost($dbLayer, 3);

        $I->amOnPage('https://localhost/');
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#menu');
        $I->seeElement('.blog-pagination');
        $I->seeElement('.blog-pagination [aria-current="page"]', ['innerText' => '1']);
        $I->seeElement('.blog-pagination a[href="/skip/2"]');
        $I->see('Post 3');
        $I->see('Post 2');
        $I->dontSee('Post 1');

        $I->amOnPage('https://localhost/skip/2');
        $I->seeResponseCodeIs(200);
        $I->seeElement('.blog-pagination [aria-current="page"]', ['innerText' => '2']);
        $I->seeElement('.blog-pagination a[href="/"]');
        $I->see('Post 1');
        $I->dontSee('Post 3');
    }

    public function testPaginationIsAbsentForOnePage(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $I->setConfigValue('S2_MAX_ITEMS', '2');
        $this->insertPost($dbLayer, 1);

        $I->amOnPage('https://localhost/');
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#menu');
        $I->dontSeeElement('.blog-pagination');
    }

    private function insertPost(DbLayer $dbLayer, int $number): void
    {
        $dbLayer
            ->insert('s2_blog_posts')
            ->setValue('create_time', ':time')->setParameter('time', 1_700_000_000 + $number)
            ->setValue('modify_time', ':time')
            ->setValue('revision', '1')
            ->setValue('title', ':title')->setParameter('title', 'Post ' . $number)
            ->setValue('text', ':text')->setParameter('text', '<p>Text ' . $number . '</p>')
            ->setValue('published', '1')
            ->setValue('favorite', '0')
            ->setValue('commented', '1')
            ->setValue('label', "''")
            ->setValue('url', ':url')->setParameter('url', 'post-' . $number)
            ->setValue('user_id', 'NULL')
            ->execute()
        ;
    }
}
