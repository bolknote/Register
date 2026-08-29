<?php

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Core\Pdo\DbLayer;

class BlogPaginationCest
{
    public function testPaginationAppearsOnlyForMultiplePages(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $I->setConfigValue('REGISTER_MAX_ITEMS', '2');

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
        $I->setConfigValue('REGISTER_MAX_ITEMS', '2');
        $this->insertPost($dbLayer, 1);

        $I->amOnPage('https://localhost/');
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#menu');
        $I->dontSeeElement('.blog-pagination');
    }

    public function testTagPageUsesTheGlobalPaginationLimit(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var TagRepository $tagRepository */
        $tagRepository = $I->grabService(TagRepository::class);
        $I->setConfigValue('REGISTER_MAX_ITEMS', '2');

        $tagId = $this->insertTag($dbLayer, 'Paginated tag', 'paginated-tag');
        foreach ([1, 2, 3] as $number) {
            $postId = $this->insertPost($dbLayer, $number);
            $tagRepository->replace(ContentId::post($postId), [$tagId]);
        }

        $I->amOnPage('https://localhost/tags/paginated-tag/');
        $I->seeResponseCodeIs(200);
        $I->see('Post 3');
        $I->see('Post 2');
        $I->dontSee('Post 1');
        $I->seeElement('#content > .tag-post-list > .post-card');
        $I->seeElement('#content > .tag-post-list > .paging [aria-current="page"]', ['innerText' => '1']);
        $I->seeElement('#content > .tag-post-list > .paging a[href="/tags/paginated-tag/?p=2"]');
        $I->seeElement('link[rel="next"][href="/tags/paginated-tag/?p=2"]');

        $I->amOnPage('https://localhost/tags/paginated-tag/?p=2');
        $I->seeResponseCodeIs(200);
        $I->see('Post 1');
        $I->dontSee('Post 3');
        $I->seeElement('.paging [aria-current="page"]', ['innerText' => '2']);
        $I->seeElement('link[rel="prev"][href="/tags/paginated-tag/?p=1"]');
    }

    public function testAuthorizedAuthorCanEditPostFromTagPage(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var TagRepository $tagRepository */
        $tagRepository = $I->grabService(TagRepository::class);
        $authorId = $this->userId($dbLayer, 'author');
        $postId   = $this->insertPost($dbLayer, 1, $authorId);
        $tagId    = $this->insertTag($dbLayer, 'Editable tag', 'editable-tag');
        $tagRepository->replace(ContentId::post($postId), [$tagId]);

        $I->login('author', 'author');
        $I->amOnPage('https://localhost/tags/editable-tag/');
        $I->seeResponseCodeIs(200);
        $I->seeElement('.site-header-tools .post-create-start[data-editor-shortcut="create"]');
        $createForm = '.site-header-shell .post-create-template .post-inplace-edit-form';
        $createTags = (string)$I->grabAttributeFrom($createForm . ' input[name="tags"]', 'value');
        $I->assertSame(
            'Editable tag',
            $createTags,
        );
        $I->seeElement(
            '.site-header-shell .post-create-template .post-tag-link[href="/tags/editable-tag/"]',
            ['innerText' => 'Editable tag'],
        );
        $I->seeElement(
            '.tag-post-list > .post-card.is-manageable[data-post-id="' . $postId . '"] > .post-inplace-tools',
        );
        $I->seeElement(
            '.tag-post-list > .post-card[data-post-id="' . $postId . '"] '
                . '> .post-inplace-edit-form[action="/_inplace/post/' . $postId . '"]',
        );

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
            'inplace_action' => 'create',
            'inplace_token'  => (string)$I->grabAttributeFrom(
                $createForm . ' input[name="inplace_token"]',
                'value',
            ),
            'revision'       => '0',
            'title'          => 'Created from a tag page',
            'body'           => '<p>Created with the current tag.</p>',
            'tags'           => $createTags,
            'published_at'   => (string)time(),
        ]);
        $I->seeResponseCodeIs(200);
        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertSame('Editable tag', $payload['tags'][0]['name'] ?? null);
        $I->assertSame('/tags/editable-tag/', $payload['tags'][0]['url'] ?? null);
    }

    public function testPostAuthorsAppearOnlyOnAMultiAuthorSite(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $authorId = $this->userId($dbLayer, 'author');
        $adminId  = $this->userId($dbLayer, 'admin');
        $this->setUserName($dbLayer, $authorId, 'Author name');
        $this->setUserName($dbLayer, $adminId, 'Admin name');

        $this->insertPost($dbLayer, 1, $authorId);

        $I->amOnPage('https://localhost/');
        $I->dontSeeElement('.post.author:not(:empty)');
        $I->amOnPage('https://localhost/post-1');
        $I->dontSeeElement('.post.author:not(:empty)');

        $secondPostId = $this->insertPost($dbLayer, 2, $adminId);
        /** @var ContentChangeDispatcher $changeDispatcher */
        $changeDispatcher = $I->grabService(ContentChangeDispatcher::class);
        $changeDispatcher->dispatch(ContentId::post($secondPostId));

        $I->amOnPage('https://localhost/');
        $I->seeElement('.post.author:not(:empty)');
        $I->see($this->userName($dbLayer, $authorId), '.post.author');
        $I->see($this->userName($dbLayer, $adminId), '.post.author');
    }

    public function testPostTagsRenderAsUnlabelledPills(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var TagRepository $tagRepository */
        $tagRepository = $I->grabService(TagRepository::class);
        $postId = $this->insertPost($dbLayer, 1);
        $firstTagId = $this->insertTag($dbLayer, 'First tag', 'first-tag');
        $secondTagId = $this->insertTag($dbLayer, 'Second tag', 'second-tag');
        $tagRepository->replace(ContentId::post($postId), [$firstTagId, $secondTagId]);

        $I->amOnPage('https://localhost/');
        $I->seeResponseCodeIs(200);
        $I->seeElement('.post-foot-meta > .post-foot-tags.post-tag-list[aria-label="Tags"] + .post-foot-views');
        $I->seeElement('.post-foot-meta .post-tag-values .post-tag-link[href="/tags/first-tag/"]');
        $I->seeElement('.post-foot-meta .post-tag-values .post-tag-link[href="/tags/second-tag/"]');
        $I->dontSeeElement('.post-foot-tags-label');
        $I->assertStringNotContainsString('</a>, <a', $I->grabResponse());
    }

    public function testCommentCountLinksToTheCommentsHeading(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId  = $this->insertPost($dbLayer, 1);
        $this->insertComment($dbLayer, $postId);

        $I->amOnPage('https://localhost/');
        $I->seeResponseCodeIs(200);
        $I->seeElement('.post-foot-comments a[href="/post-1#comments-title"][data-comment-count="1"]');

        $I->amOnPage('https://localhost/post-1');
        $I->seeElement('#comments-title');
    }

    private function insertPost(DbLayer $dbLayer, int $number, ?int $authorId = null): int
    {
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::POST->value)
            ->setValue('slug_scope', "'root'")
            ->setValue('created_at', ':time')->setParameter('time', 1_700_000_000 + $number)
            ->setValue('published_at', ':time')
            ->setValue('updated_at', ':time')
            ->setValue('revision', '1')
            ->setValue('title', ':title')->setParameter('title', 'Post ' . $number)
            ->setValue('excerpt', "''")
            ->setValue('body', ':text')->setParameter('text', '<p>Text ' . $number . '</p>')
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('series', "''")
            ->setValue('slug', ':url')->setParameter('url', 'post-' . $number)
            ->setValue('author_id', ':author_id')->setParameter('author_id', $authorId)
            ->execute()
        ;

        return (int)$dbLayer->insertId();
    }

    private function insertTag(DbLayer $dbLayer, string $name, string $slug): int
    {
        $dbLayer->insert('tags')->values([
            'name'        => ':name',
            'description' => "''",
            'modify_time' => ':modify_time',
            'url'         => ':url',
        ])->execute([
            'name'        => $name,
            'modify_time' => time(),
            'url'         => $slug,
        ]);

        return (int)$dbLayer->insertId();
    }

    private function insertComment(DbLayer $dbLayer, int $postId): void
    {
        $dbLayer->insert(CommentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'content_id'   => ':content_id',
            'time'         => ':time',
            'ip'           => "'127.0.0.1'",
            'nick'         => "'Reader'",
            'email'        => "'reader@example.test'",
            'shown'        => '1',
            'text'         => "'Comment'",
        ])->execute([
            'content_type' => ContentType::POST->value,
            'content_id'   => $postId,
            'time'         => time(),
        ]);
    }

    private function userId(DbLayer $dbLayer, string $login): int
    {
        return (int)$dbLayer
            ->select('id')
            ->from('users')
            ->where('login = :login')->setParameter('login', $login)
            ->execute()
            ->result()
        ;
    }

    private function userName(DbLayer $dbLayer, int $userId): string
    {
        return (string)$dbLayer
            ->select('name')
            ->from('users')
            ->where('id = :id')->setParameter('id', $userId)
            ->execute()
            ->result()
        ;
    }

    private function setUserName(DbLayer $dbLayer, int $userId, string $name): void
    {
        $dbLayer
            ->update('users')
            ->set('name', ':name')->setParameter('name', $name)
            ->where('id = :id')->setParameter('id', $userId)
            ->execute()
        ;
    }
}
