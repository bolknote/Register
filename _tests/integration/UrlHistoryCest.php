<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;

/** Uses the actual editorial HTTP endpoints instead of manually calling alias methods. */
final class UrlHistoryCest
{
    public function postEditorKeepsOldUrlsAndRejectsStaleAndCollidingRenames(\IntegrationTester $I): void
    {
        $db = $I->grabService(DbLayer::class);
        $postId = $this->content($db, 'first-url', ContentType::POST);
        $this->content($db, 'occupied-url', ContentType::POST);
        $I->amOnPage('https://localhost/first-url');
        $I->seeResponseCodeIs(200);
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/first-url');

        $token = (string)$I->grabAttributeFrom('[data-post-id="' . $postId . '"] .post-inplace-edit-form input[name="inplace_token"]', 'value');
        $I->seeElement('.post-url-editor input[name="slug"][value="first-url"]');
        $request = [
            'inplace_action' => 'edit', 'inplace_token' => $token,
            'revision' => '1', 'title' => 'URL history', 'body' => '<p>Original body.</p>', 'slug' => 'second-url',
        ];
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, $request);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals('/second-url', ['url']);
        $I->assertJsonSubResponseEquals(true, ['url_changed']);
        $this->redirect($I, '/first-url', '/second-url');

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [...$request, 'slug' => 'third-url']);
        $I->seeResponseCodeIs(409);
        $this->redirect($I, '/first-url', '/second-url');

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [...$request, 'revision' => '2', 'slug' => 'occupied-url']);
        $I->seeResponseCodeIs(422);
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [...$request, 'revision' => '2', 'slug' => 'third-url']);
        $I->seeResponseCodeIs(200);
        $this->redirect($I, '/first-url?from=archive', '/third-url?from=archive');
        $this->redirect($I, '/second-url', '/third-url');

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [...$request, 'revision' => '3', 'slug' => 'first-url']);
        $I->seeResponseCodeIs(200);
        $this->redirect($I, '/third-url', '/first-url');
        $I->amOnPage('https://localhost/first-url');
        $I->seeResponseCodeIs(200);
    }

    public function pageAdminRenamePreservesTheWholeBranch(\IntegrationTester $I): void
    {
        $db = $I->grabService(DbLayer::class);
        $rootId = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)->where('parent_id IS NULL')->andWhere("content_type = 'page'")->execute()->result();
        $pageId = $this->content($db, 'old-section', ContentType::PAGE, $rootId);
        $this->content($db, 'child', ContentType::PAGE, $pageId);
        $I->amOnPage('https://localhost/old-section/child');
        $I->seeResponseCodeIs(200);
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $pageId);
        $I->submitForm('form[name="article-form"]', ['slug' => 'new-section']);
        $I->seeResponseCodeIs(302);
        $this->redirect($I, '/old-section/child', '/new-section/child');
        $this->redirect($I, '/old-section/', '/new-section/');
    }

    public function tagAdminRenamePreservesPageAndBothSubscriptionFormats(\IntegrationTester $I): void
    {
        $db = $I->grabService(DbLayer::class);
        $db->insert('tags')->values(['name' => "'History'", 'description' => "''", 'url' => "'old-tag'", 'modify_time' => '1'])->execute();
        $tagId = (int)$db->insertId();
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Tag&action=edit&id=' . $tagId);
        $I->submitForm('.edit-content > form', ['url' => 'new-tag']);
        $I->seeResponseCodeIs(302);
        $this->redirect($I, '/tags/old-tag/', '/tags/new-tag/');
        $this->redirect($I, '/tags/old-tag/rss', '/tags/new-tag/rss');
        $this->redirect($I, '/tags/old-tag/feed.json', '/tags/new-tag/feed.json');
    }

    private function content(DbLayer $db, string $slug, ContentType $type, ?int $parentId = null): int
    {
        $adminId = (int)$db->select('id')->from('users')->where("login = 'admin'")->execute()->result();
        $rootId = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)->where('parent_id IS NULL')->andWhere("content_type = 'page'")->execute()->result();
        $db->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':type', 'parent_id' => ':parent', 'slug_scope' => ':scope', 'slug' => ':slug',
            'title' => "'URL history'", 'excerpt' => "''", 'body' => "'<p>Original body.</p>'",
            'created_at' => '1', 'published_at' => '1', 'updated_at' => '1', 'published' => '1', 'author_id' => ':author',
        ])->execute(['type' => $type->value, 'parent' => $parentId, 'scope' => $parentId === null || $parentId === $rootId ? 'root' : 'page:' . $parentId, 'slug' => $slug, 'author' => $adminId]);

        return (int)$db->insertId();
    }

    private function redirect(\IntegrationTester $I, string $oldPath, string $newPath): void
    {
        $I->amOnPage('https://localhost' . $oldPath);
        $I->seeResponseCodeIs(301);
        $I->seeLocationIs($newPath);
    }
}
