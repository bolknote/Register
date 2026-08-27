<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\PDO;

final class BlogPageResponseCacheCest
{
    public function servesTheAnonymousFirstPageHitWithoutDatabaseQueries(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached first page', 'cached-first-page');

        $I->amOnPage('/');
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->see('Cached first page');

        $I->amOnPage('/');
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->see('Cached first page');

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());
    }

    public function servesTheAnonymousAllPostsHitWithoutDatabaseQueries(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached archive', 'cached-archive');

        $I->amOnPage('/all/');
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->see('Cached archive');

        $I->amOnPage('/all/');
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->see('Cached archive');

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());
    }

    public function servesRepeatedCrawlerContentWithoutDatabaseQueries(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached crawler post', 'cached-crawler-post');

        $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; YandexBot/3.0)'];
        $I->sendRequestWithHeaders('/cached-crawler-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->see('Cached crawler post');
        $I->dontSeeElement('#add-comment');

        $I->sendRequestWithHeaders('/cached-crawler-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->see('Cached crawler post');

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());

        $I->sendRequestWithHeaders('/cached-crawler-post', ['User-Agent' => 'Mozilla/5.0 integration browser']);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->seeElement('#add-comment');
    }

    private function insertPost(DbLayer $dbLayer, string $title, string $slug): void
    {
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::POST->value)
            ->setValue('slug_scope', "'root'")
            ->setValue('created_at', ':time')->setParameter('time', 1_700_000_001)
            ->setValue('published_at', ':time')
            ->setValue('updated_at', ':time')
            ->setValue('revision', '1')
            ->setValue('title', ':title')->setParameter('title', $title)
            ->setValue('excerpt', "''")
            ->setValue('body', "'<p>Text</p>'")
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('series', "''")
            ->setValue('slug', ':slug')->setParameter('slug', $slug)
            ->setValue('author_id', 'NULL')
            ->execute()
        ;
    }
}
