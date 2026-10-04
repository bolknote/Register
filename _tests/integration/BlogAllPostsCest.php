<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Symfony\Component\DomCrawler\Crawler;
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
        $I->assertSame('/all/newest-post', $I->grabAttributeFrom('.blog-all-posts-list p:first-child a', 'href'));
        $I->dontSee('Unpublished post', '.blog-all-posts');
    }

    public function testPostIsPublishedInTheAllNamespace(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Root permalink', 'root-permalink', 1_700_000_005, true);

        $I->amOnPage('/all/root-permalink');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->see('Root permalink', '.post.head');

        $configuredPrefix = $dbLayer
            ->select('COUNT(*)')
            ->from('config')
            ->where("name = 'REGISTER_BLOG_URL'")
            ->execute()
            ->result()
        ;
        $I->assertSame(0, (int)$configuredPrefix);
    }

    public function testRussianTitleQuotesAreTheSameForGuestsAndAuthors(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $titles = [
            '«Первый»: заголовок',
            '«Второй»: заголовок',
            '"Старый заголовок"',
            'Книга: "Мир «слов»"',
        ];
        $ids = [];
        foreach ($titles as $index => $title) {
            $ids[] = $this->insertPost($dbLayer, $title, 'quoted-title-' . $index, 1_700_000_004 - $index, true);
        }

        $I->setConfigValue('REGISTER_LANGUAGE', 'Russian');
        $expected = ['«Первый»: заголовок', '«Второй»: заголовок', '«Старый заголовок»', 'Книга: «Мир „слов“»'];

        $I->amOnPage('https://localhost/all/');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->assertSame($expected, $I->grabMultiple('.blog-all-posts-list p'));
        $I->dontSeeElement('.post-create-template');
        $this->assertPartialIndexTitleQuotes($I, $expected);

        // The login helper expects the English administration labels.
        $I->setConfigValue('REGISTER_LANGUAGE', 'English');
        $I->login('admin', 'admin');
        $I->setConfigValue('REGISTER_LANGUAGE', 'Russian');
        $I->amOnPage('https://localhost/all/');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->seeElement('.post-create-template');
        $I->assertSame($expected, $I->grabMultiple('.blog-all-posts-list p'));
        $this->assertPartialIndexTitleQuotes($I, $expected);

        foreach ($ids as $index => $id) {
            $storedTitle = $dbLayer->select('title')->from(ContentSchema::TABLE_NAME)
                ->where('id = :id')->setParameter('id', $id)->execute()->result();
            $I->assertSame($titles[$index], $storedTitle, 'Rendering must not rewrite stored titles.');
        }
    }

    /** @param list<string> $expected */
    private function assertPartialIndexTitleQuotes(\IntegrationTester $I, array $expected): void
    {
        $I->sendRequestWithHeaders('https://localhost/all/', [
            'User-Agent' => 'Mozilla/5.0 integration browser',
            'X-Register-Navigation' => 'partial',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsArray($payload);
        $I->assertIsString($payload['fragment']);

        $crawler = new Crawler();
        $crawler->addHtmlContent($payload['fragment'], 'UTF-8');

        $I->assertSame($expected, $crawler->filter('.blog-all-posts-list p')->each(
            static fn(Crawler $node): string => $node->text(),
        ));
    }

    public function testContentChangeInvalidatesTheCachedIndex(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer, 'Cached post', 'cached-post', 1_700_000_001, true);

        $I->amOnPage('/all/');
        $I->see('Cached post', '.blog-all-posts');

        $newPostId = $this->insertPost($dbLayer, 'New post after cache', 'new-post-after-cache', 1_700_000_002, true);
        $I->amOnPage('/all/');
        $I->dontSee('New post after cache', '.blog-all-posts');

        /** @var ContentChangeDispatcher $changeDispatcher */
        $changeDispatcher = $I->grabService(ContentChangeDispatcher::class);
        $changeDispatcher->dispatch(ContentId::post($newPostId));

        $I->amOnPage('/all/');
        $I->see('New post after cache', '.blog-all-posts');
    }

    public function testMissingPostUsesAVisiblePageHeading(\IntegrationTester $I): void
    {
        $I->amOnPage('/missing-post-layout-test');

        $I->seeResponseCodeIs(Response::HTTP_NOT_FOUND);
        $I->see('No posts', 'h1');
        $I->dontSeeElement('#content > p:first-child');
    }

    private function insertPost(
        DbLayer $dbLayer,
        string $title,
        string $url,
        int $timestamp,
        bool $published,
    ): int {
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::POST->value)
            ->setValue('slug_scope', "'root'")
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
            ->setValue('slug', ':url')->setParameter('url', 'all/' . $url)
            ->setValue('author_id', 'NULL')
            ->execute()
        ;

        return (int)$dbLayer->insertId();
    }
}
