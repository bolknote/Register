<?php

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentRepository;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\ContentViewRepository;
use Register\Core\Pdo\DbLayer;

final class PostFooterCest
{
    public function savedFooterPreservesCountsAndCommentVisibility(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/');

        $values = [
            'inplace_action' => 'create',
            'inplace_token' => (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value'),
            'title' => 'Ordinary footer regression', 'body' => '<p>A normal post.</p>',
            'tags' => 'Normal tag', 'published_at' => (string)(time() - 60),
        ];
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', $values);
        $I->seeResponseCodeIs(200);

        $created = $I->grabJson();
        $I->assertIsArray($created);
        $contentId = ContentId::post((int)$created['id']);
        $comments = $I->grabService(CommentRepository::class);
        $shownId = $comments->save($contentId, 'Reader', 'reader@example.test', false, 'Shown comment.', '127.0.0.1', null);
        $comments->publish($shownId, ContentType::POST);
        $comments->save($contentId, 'Other reader', 'other@example.test', false, 'Not published.', '127.0.0.1', null);

        $views = $I->grabService(ContentViewRepository::class);
        $views->record($contentId);
        $views->record($contentId);

        $edit = [...$values, 'inplace_action' => 'edit', 'inplace_token' => $created['token'], 'revision' => '1'];
        $I->sendAjaxPostRequest('https://localhost' . $created['action_url'], $edit);
        $I->seeResponseCodeIs(200);

        $saved = $I->grabJson();
        $I->assertIsArray($saved);
        $I->assertStringContainsString('data-comment-count="1"', $saved['foot_html']);
        $I->assertStringContainsString('href="' . $created['url'] . '#comments-title"', $saved['foot_html']);
        $I->assertStringContainsString('class="post-foot-views-count" aria-hidden="true">2</span>', $saved['foot_html']);
        $I->assertStringContainsString('Normal tag', $saved['foot_html']);
        $I->assertStringNotContainsString('register-deferred-', $saved['foot_html']);

        $db = $I->grabService(DbLayer::class);
        $db->update(ContentSchema::TABLE_NAME)->set('comments_enabled', '0')
            ->where('id = :id')->setParameter('id', $created['id'])->execute();
        $I->sendAjaxPostRequest('https://localhost' . $created['action_url'], $edit);
        $I->seeResponseCodeIs(200);

        $disabled = $I->grabJson();
        $I->assertIsArray($disabled);
        $I->assertStringNotContainsString('post-foot-comments', $disabled['foot_html']);
        $I->assertStringContainsString('data-register-reactions', $disabled['foot_html']);

        $db->update(ContentSchema::TABLE_NAME)->set('comments_enabled', '1')
            ->where('id = :id')->setParameter('id', $created['id'])->execute();
        $I->setConfigValue('REGISTER_SHOW_COMMENTS', '0');
        $I->sendAjaxPostRequest('https://localhost' . $created['action_url'], $edit);
        $I->seeResponseCodeIs(200);

        $hidden = $I->grabJson();
        $I->assertIsArray($hidden);
        $I->assertStringNotContainsString('post-foot-comments', $hidden['foot_html']);
        $I->assertStringContainsString('data-register-reactions', $hidden['foot_html']);
    }
}
