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
        $I->submitForm('form', [
            'title' => 'Привет, мир!',
            'text'  => '<p>Start text</p>',
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

        $I->assertSame('privet-mir', $I->grabValueFrom('input[name=url]'));

        $dataProvider = (static fn(string $csrfToken, string $userId): array => [
            '__csrf_token' => $csrfToken,
            'title'        => 'New Blog Post Title',
            'tags'         => 'tag1, blog tag',
            'create_time'  => '2023-08-12T11:32',
            'modify_time'  => '2023-08-12T12:15',
            'text'         => '<p>New blog post with some text</p>',
            'user_id'      => $userId,
            'label'        => '',
            'revision'     => '1',
            'url'          => 'new_post1',

            'commented' => '1',
            'published' => '1',
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
        $I->assertSame('/new_post1', $post->path);
        $I->assertSame('Main page', $mainPage->title);
        $I->assertSame('/', $mainPage->path);

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

        $postText = $I->grabValueFrom('textarea[name=text]');
        $I->assertStringContainsString('New blog post', $postText);

        // Reopen the list in the admin panel
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');
        $I->canSee('2023-08-12');
        $I->see('New Blog Post Title');

//        foreach (['/2023/08/12/', '/2023/08/'] as $url) {
//            $I->amOnPage($url);
//            $I->see('New Blog Post Title');
//            $I->see('New blog post');
//            $I->see('August 12, 2023');
//        }

        // Open a public page
        $I->amOnPage('https://localhost/new_post1');
        $I->see('New Blog Post Title');
        $I->see('New blog post');
        $I->see('August 12, 2023');


        /**
         * 2. Search
         */
        $I->submitForm('form.s2_search_form', [
            'q' => 'some text',
        ]);

        // Indexing is not done yet
        $I->see('No results found for your query.');

        // Run indexing
        /** @var QueueConsumer $consumer */
        $consumer = $I->grabService(QueueConsumer::class);
        $I->assertTrue($consumer->runQueue());

        $I->amOnPage('https://localhost/?search=1&q=some+text');
        $I->see('New blog post with <span class="s2_search_highlight">some text</span>');

        // $I->canWriteComment();

        $I->amOnPage('https://localhost/?search=1&q=another+tag');
        $I->see('<a href="/tags/blog%20tag/">blog tag</a>');
    }
}
