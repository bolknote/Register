<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentSchema;
use Register\Module\Search\Service\ContentIndexer;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Queue\QueueConsumer;

/**
 * @group search
 */
class SearchCest
{
    public function tryToTest(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');

        /**
         * 1. Create a blog post
         */
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=new');
        $I->seeResponseCodeIs(200);
        $I->submitForm('form[name="article-form"]', [
            'title' => 'Привет, мир!',
            'body'  => '<p>Start text</p>',
        ]);
        $I->seeResponseCodeIs(302);

        $url = $I->grabLocation();
        if (preg_match('~id=(\d+)~', $url, $matches) !== 1) {
            throw new \RuntimeException('The created post redirect does not contain an identifier.');
        }

        $postId = $matches[1];

        $I->followRedirect();
        $userId    = $I->grabAttributeFrom('[data-user-id]', 'data-user-id');
        $csrfToken = $I->grabValueFrom('input[name=__csrf_token]');
        if ($userId === null) {
            throw new \RuntimeException('The blog post form does not expose a user identifier.');
        }

        $I->assertSame('privet-mir', $I->grabValueFrom('input[name=slug]'));
        $I->assertSame('', $I->grabValueFrom('input[name=date_label]'));

        $dataProvider = (static fn(
            string $csrfToken,
            string $userId,
            string $revision = '1',
            string $body = '<p>New blog post with some text</p>',
            bool $published = true,
        ): array => [
            '__csrf_token' => $csrfToken,
            'title'        => 'New Blog Post Title',
            'tags'         => 'tag1, blog tag, міръ, отрок',
            'published_at' => '2023-08-12T11:32',
            'date_label'   => 'лето 1977 года',
            'updated_at'   => '2023-08-12T12:15',
            'body'         => $body,
            'author_id'    => $userId,
            'series'       => '',
            'revision'     => $revision,
            'slug'         => 'new-post1',

            'comments_enabled' => '1',
            ...$published ? ['published' => '1'] : [],
        ]);
        // Secondary check beyond the search, but let it be
        $I->sendAjaxPostRequest('https://localhost/_admin/index.php?entity=BlogPost&action=edit&id=' . ((int)$postId + 1111), $dataProvider($csrfToken, $userId));
        $I->assertJsonSubResponseContains('Unable to confirm security token.', ['errors', 0]);

        $I->sendAjaxPostRequest('https://localhost/_admin/index.php?entity=BlogPost&action=edit&id=' . $postId, $dataProvider($csrfToken, $userId));
        $I->seeResponseCodeIs(200);
        $I->see('"success":true');
        $I->see('"urlStatus":"ok"');
        $I->see('"urlTitle":""');
        $I->see('"revision":"2"');

        /** @var ContentRepository $contentRepository */
        $contentRepository = $I->grabService(ContentRepository::class);
        $post              = $contentRepository->find(ContentId::post((int)$postId));
        $mainPage          = $contentRepository->find(ContentId::page(1));
        if (!$post instanceof ContentItem || !$mainPage instanceof ContentItem) {
            throw new \RuntimeException('The unified content repository did not expose the created post and main page.');
        }

        $I->assertSame('New Blog Post Title', $post->title);
        $I->assertSame('/new-post1', $post->path);
        $I->assertSame(strtotime('2023-08-12T11:32'), $post->publishedAt);
        $I->assertSame('Main page', $mainPage->title);
        $I->assertSame('/', $mainPage->path);

        $I->amOnPage('https://localhost/privet-mir');
        $I->seeResponseCodeIs(301);
        $I->seeLocationIs('/new-post1');

        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $queued  = $dbLayer
            ->select('COUNT(*)')
            ->from('queue')
            ->where('id = :id')->setParameter('id', (string)ContentId::post((int)$postId))
            ->andWhere('code = :code')->setParameter('code', ContentIndexer::QUEUE_CODE)
            ->execute()
            ->result()
        ;
        $I->assertSame(1, (int)$queued);

        // Reopen the edit form in the admin panel
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=edit&id=' . $postId);

        $postText = $I->grabValueFrom('textarea[name=body]');
        $I->assertStringContainsString('New blog post', $postText);
        $I->assertSame('лето 1977 года', $I->grabValueFrom('input[name=date_label]'));

        // Reopen the list in the admin panel
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');
        $I->canSee('2023-08-12');
        $I->see('New Blog Post Title');

        // Open a public page
        $I->amOnPage('https://localhost/new-post1');
        $I->see('New Blog Post Title');
        $I->see('New blog post');
        $I->see('лето 1977 года');
        $I->dontSee('August 12, 2023');
        $I->assertStringContainsString(
            'datetime="' . date(DATE_ATOM, $post->publishedAt) . '"',
            $I->grabResponse(),
        );

        // The internal timestamp still determines the archive location.
        $I->amOnPage('https://localhost/archive/2023/08/12/');
        $I->see('New Blog Post Title');
        $I->see('лето 1977 года');

        // An empty display date falls back to the localized internal date and time.
        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('date_label', ':date_label')->setParameter('date_label', '')
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()
        ;
        $I->amOnPage('https://localhost/new-post1');
        $I->see('August 12, 2023');
        $I->dontSee('лето 1977 года');


        /**
         * 2. Search
         */
        $I->submitForm('form.s2_search_form', [
            'q' => 'some text',
        ]);

        // Indexing is not done yet
        $I->see('No results found for your query.');

        $quickSearchUrl = $I->grabAttributeFrom('#s2_search_input_ext', 'data-s2-search-url');
        $I->assertNotNull($quickSearchUrl);
        $I->assertStringContainsString('title=', $quickSearchUrl);

        // Run indexing
        /** @var QueueConsumer $consumer */
        $consumer = $I->grabService(QueueConsumer::class);
        $I->assertTrue($consumer->runQueue());
        while ($consumer->runQueue());

        $I->amOnPage('https://localhost/?search=1&q=some+text');
        $I->see('New blog post with <span class="s2_search_highlight">some text</span>');
        $I->dontSeeElement('.paging');

        // $I->canWriteComment();

        $I->amOnPage('https://localhost/?search=1&q=another+tag');
        $I->see('<a href="/tags/blog%20tag/">blog tag</a>');

        // Historical and modern tag spellings are matched symmetrically.
        $I->amOnPage('https://localhost/?search=1&q=' . rawurlencode('мир'));
        $I->see('міръ');
        $I->amOnPage('https://localhost/?search=1&q=' . rawurlencode('ѿрокъ'));
        $I->see('отрок');

        /**
         * 3. Automatic lifecycle updates
         */
        $I->sendAjaxPostRequest(
            'https://localhost/_admin/index.php?entity=BlogPost&action=edit&id=' . $postId,
            $dataProvider($csrfToken, $userId, '2', published: false),
        );
        $I->seeResponseCodeIs(200);
        $I->see('"revision":"3"');
        while ($consumer->runQueue());

        $I->amOnPage('https://localhost/?search=1&q=some+text');
        $I->see('No results found for your query.');

        $I->sendAjaxPostRequest(
            'https://localhost/_admin/index.php?entity=BlogPost&action=edit&id=' . $postId,
            $dataProvider(
                $csrfToken,
                $userId,
                '3',
                '<p>Replacement searchable text</p>',
            ),
        );
        $I->seeResponseCodeIs(200);
        $I->see('"revision":"4"');
        while ($consumer->runQueue());

        $I->amOnPage('https://localhost/?search=1&q=replacement+searchable');
        $I->see('Replacement');
        $I->see('searchable');
        $I->dontSee('No results found for your query.');

        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');

        $deleteUrl   = '?entity=BlogPost&action=delete&id=' . $postId;
        $deleteToken = $I->grabAttributeFrom('[data-delete-url="' . $deleteUrl . '"]', 'data-csrf-token');
        if ($deleteToken === null || $deleteToken === '') {
            throw new \RuntimeException('The post delete action does not contain a CSRF token.');
        }

        $I->sendAjaxPostRequest(
            'https://localhost/_admin/index.php' . $deleteUrl,
            ['csrf_token' => $deleteToken],
        );
        $I->seeResponseCodeIs(200);
        $I->see('"success":true');
        while ($consumer->runQueue());

        $I->amOnPage('https://localhost/?search=1&q=replacement+searchable');
        $I->see('No results found for your query.');
        $I->assertNull($contentRepository->find(ContentId::post((int)$postId)));

    }
}
