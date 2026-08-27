<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueueConsumer;
use Register\Module\Search\Service\ContentIndexer;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group search
 */
class SearchCest
{
    public function tryToTest(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');

        /**
         * 1. Create and edit a blog post through the public inline editor.
         */
        $I->amOnPage('https://localhost/');

        $formSelector = '.site-header-shell .post-create-template .post-inplace-edit-form';
        $token = (string)$I->grabAttributeFrom($formSelector . ' input[name="inplace_token"]', 'value');
        $publishedAt = strtotime('2023-08-12T11:32');

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
            'inplace_action' => 'create',
            'inplace_token'  => $token,
            'revision'       => '0',
            'title'          => 'new-post1',
            'body'           => '<p>Start text</p>',
            'tags'           => '',
            'published_at'   => (string)$publishedAt,
        ]);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $created = $I->grabJson();
        $I->assertIsArray($created);
        $I->assertSame('/new-post1', $created['url']);

        $postId = (int)$created['id'];

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [
            'inplace_action' => 'edit',
            'inplace_token'  => $created['token'],
            'revision'       => '1',
            'title'          => 'New Blog Post Title',
            'tags'           => 'tag1, blog tag, міръ, отрок',
            'published_at'   => (string)$publishedAt,
            'body'           => '<p>New blog post with some text</p>',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $edited = $I->grabJson();
        $I->assertIsArray($edited);
        $I->assertSame('edit', $edited['action']);
        $I->assertSame(2, $edited['revision']);

        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('date_label', ':date_label')->setParameter('date_label', 'лето 1977 года')
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()
        ;
        /** @var ContentChangeDispatcher $changeDispatcher */
        $changeDispatcher = $I->grabService(ContentChangeDispatcher::class);
        $changeDispatcher->dispatch(ContentId::post($postId));

        /** @var ContentRepository $contentRepository */
        $contentRepository = $I->grabService(ContentRepository::class);
        $post              = $contentRepository->find(ContentId::post($postId));
        $mainPage          = $contentRepository->find(ContentId::page(1));
        if (!$post instanceof ContentItem || !$mainPage instanceof ContentItem) {
            throw new \RuntimeException('The unified content repository did not expose the created post and main page.');
        }

        $I->assertSame('New Blog Post Title', $post->title);
        $I->assertSame('/new-post1', $post->path);
        $I->assertSame($publishedAt, $post->publishedAt);
        $I->assertSame('Main page', $mainPage->title);
        $I->assertSame('/', $mainPage->path);

        $queued = $dbLayer
            ->select('COUNT(*)')
            ->from('queue')
            ->where('id = :id')->setParameter('id', (string)ContentId::post($postId))
            ->andWhere('code = :code')->setParameter('code', ContentIndexer::QUEUE_CODE)
            ->execute()
            ->result()
        ;
        $I->assertSame(1, (int)$queued);

        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');
        $I->canSee('2023-08-12');
        $I->see('New Blog Post Title');
        $I->dontSeeElement('a.entity-action-new');
        $I->dontSeeElement('a.list-action-link-edit');

        $I->amOnPage('https://localhost/new-post1');
        $I->see('New Blog Post Title');
        $I->see('New blog post');
        $I->see('лето 1977 года');
        $I->dontSee('August 12, 2023');
        $I->assertStringContainsString(
            'datetime="' . date(DATE_ATOM, $post->publishedAt) . '"',
            $I->grabResponse(),
        );

        $I->amOnPage('https://localhost/archive/2023/08/12/');
        $I->see('New Blog Post Title');
        $I->see('лето 1977 года');

        // An empty display date falls back to the localized internal date and time.
        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('date_label', ':date_label')->setParameter('date_label', '')
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()
        ;
        $changeDispatcher->dispatch(ContentId::post($postId));
        $I->amOnPage('https://localhost/new-post1');
        $I->see('August 12, 2023');
        $I->dontSee('лето 1977 года');

        /**
         * 2. Search.
         */
        $I->seeElement('.register_search_form .register-search-autocomplete > #register_search_input');
        $I->submitForm('form.register_search_form', ['q' => 'some text']);

        $I->see('No results found for your query.');

        $quickSearchUrl = $I->grabAttributeFrom('#register_search_input_ext', 'data-register-search-url');
        $I->assertNotNull($quickSearchUrl);
        $I->assertStringContainsString('title=', $quickSearchUrl);
        $I->seeElement('.search-form .register-search-autocomplete > #register_search_input_ext');

        /** @var QueueConsumer $consumer */
        $consumer = $I->grabService(QueueConsumer::class);
        $I->assertTrue($consumer->runQueue());
        while ($consumer->runQueue());

        $I->amOnPage('https://localhost/?search=1&q=some+text');
        $I->see('New blog post with <span class="register_search_highlight">some text</span>');
        $I->seeElement('.search-results > article.search-result .search-result-title');
        $I->dontSeeElement('.paging');

        $I->amOnPage('https://localhost/?search=1&q=another+tag');
        $I->see('<a href="/tags/blog%20tag/">blog tag</a>');

        $I->amOnPage('https://localhost/?search=1&q=' . rawurlencode('мир'));
        $I->see('міръ');
        $I->amOnPage('https://localhost/?search=1&q=' . rawurlencode('ѿрокъ'));
        $I->see('отрок');

        /**
         * 3. Automatic lifecycle updates through the list-only post administration.
         */
        $bulkAction = function (string $action) use ($I, $postId): void {
            $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');
            $csrfToken = $I->grabAttributeFrom('[data-bulk-list]', 'data-csrf-token');
            $I->assertNotNull($csrfToken);
            $items = json_encode([[
                'primary_key' => ['id' => $postId],
                'csrf_token'  => '',
            ]], JSON_THROW_ON_ERROR);
            $I->sendPost('https://localhost/_admin/ajax.php?action=register_bulk_list_action', [
                'entity'      => 'BlogPost',
                'bulk_action' => $action,
                'csrf_token'  => $csrfToken,
                'items'       => $items,
            ]);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $I->assertJsonSubResponseEquals(1, ['updated']);
        };

        $bulkAction('unpublish');
        while ($consumer->runQueue());

        $I->amOnPage('https://localhost/?search=1&q=some+text');
        $I->see('No results found for your query.');

        $bulkAction('publish');
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [
            'inplace_action' => 'edit',
            'inplace_token'  => $created['token'],
            'revision'       => '2',
            'title'          => 'New Blog Post Title',
            'tags'           => 'tag1, blog tag, міръ, отрок',
            'published_at'   => (string)$publishedAt,
            'body'           => '<p>Replacement searchable text</p>',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->assertSame(3, $I->grabJson()['revision'] ?? null);
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
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->see('"success":true');
        while ($consumer->runQueue());

        $I->amOnPage('https://localhost/?search=1&q=replacement+searchable');
        $I->see('No results found for your query.');
        $I->assertNull($contentRepository->find(ContentId::post($postId)));
    }
}
