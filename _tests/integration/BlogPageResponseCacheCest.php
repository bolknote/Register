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

        $I->amOnPage('/?utm_source=integration-test');
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

        $I->amOnPage('/all/?unused=1');
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

    public function servesRepeatedBrowserPrefetchWithoutDatabaseQueries(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached prefetched post', 'cached-prefetched-post');

        $headers = [
            'User-Agent' => 'Mozilla/5.0 integration browser',
            'Purpose' => 'prefetch',
        ];
        $I->sendRequestWithHeaders('/cached-prefetched-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->see('Cached prefetched post');
        $I->dontSeeElement('#add-comment');

        $I->sendRequestWithHeaders('/cached-prefetched-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());
    }

    public function hydratesReplyStateAfterReusingTheBrowserContentShell(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached browser post', 'cached-browser-post');

        $headers = ['User-Agent' => 'Mozilla/5.0 integration browser'];
        $I->sendRequestWithHeaders('/cached-browser-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->seeElement('#comment-form');

        $firstTextField = (string)$I->grabAttributeFrom('#comment-text', 'name');
        $I->assertStringNotContainsString('register-deferred-comment-form', $I->grabResponse());

        $I->sendRequestWithHeaders(
            '/cached-browser-post?reply_to=20583&reply_number=19&reply_name=vrann.livejournal.com',
            $headers,
        );
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->seeElement('#comment-form');
        $I->assertSame('20583', $I->grabAttributeFrom('.comment-parent-id', 'value'));
        $I->assertSame('19', $I->grabAttributeFrom('.comment-reply-number', 'value'));
        $I->assertSame('vrann.livejournal.com', $I->grabAttributeFrom('.comment-reply-name', 'value'));
        $I->assertNotSame($firstTextField, (string)$I->grabAttributeFrom('#comment-text', 'name'));
        $I->assertStringNotContainsString('register-deferred-comment-form', $I->grabResponse());

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog());
    }

    public function hydratesAValidReplyFormInCachedPartialNavigation(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached partial post', 'cached-partial-post');

        $headers = [
            'User-Agent' => 'Mozilla/5.0 integration browser',
            'X-Register-Navigation' => 'partial',
        ];
        $I->sendRequestWithHeaders('/cached-partial-post', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');

        $I->sendRequestWithHeaders('/cached-partial-post?reply_to=42&reply_number=7&reply_name=Reader', $headers);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsArray($payload);
        $fragment = $payload['fragment'] ?? null;
        $I->assertIsString($fragment);
        $I->assertStringContainsString('id="comment-form"', $fragment);
        $I->assertStringContainsString('class="comment-parent-id"', $fragment);
        $I->assertStringContainsString('value="42"', $fragment);
        $I->assertStringContainsString('Reader', $fragment);
        $I->assertStringNotContainsString('register-deferred-comment-form', $fragment);
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
