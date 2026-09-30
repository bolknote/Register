<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Live\LiveUpdateRepository;

final class PostCreateRetryCest
{
    public function repeatingACreationReturnsTheFirstPost(\IntegrationTester $I): void
    {
        $values = $this->form($I);
        $db = $I->grabService(DbLayer::class);
        $url = 'https://localhost/_inplace/post/new';
        $I->sendAjaxPostRequest($url, $values);
        $I->seeResponseCodeIs(200);

        $first = $I->grabJson();
        $I->assertIsArray($first);
        $count = (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result();
        $cursor = $I->grabService(LiveUpdateRepository::class)->currentCursor();

        // The author never received the first response and submits the same operation again.
        $I->sendAjaxPostRequest($url, $values);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals($first['id'], ['id']);
        $I->assertJsonSubResponseEquals(true, ['replayed']);
        $I->assertSame($count, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result());
        $I->assertSame($cursor, $I->grabService(LiveUpdateRepository::class)->currentCursor());

        // Editing the draft after the lost response must still identify the original creation.
        $I->sendAjaxPostRequest($url, [...$values, 'body' => '<p>Later changes.</p>']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals($first['id'], ['id']);
        $I->assertJsonSubResponseEquals(false, ['request_matched']);
        $I->assertSame('<p>Original creation.</p>', $db->select('body')->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $first['id'])->execute()->result());
        $I->assertSame($count, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result());
    }

    public function failedValidationAllowsCorrectionWithTheSameOperation(\IntegrationTester $I): void
    {
        $values = $this->form($I);
        $url = 'https://localhost/_inplace/post/new';
        $I->sendAjaxPostRequest($url, [...$values, 'title' => '']);
        $I->seeResponseCodeIs(422);
        $I->sendAjaxPostRequest($url, $values);
        $I->seeResponseCodeIs(200);

        $first = $I->grabJson();
        $I->assertIsArray($first);
        $I->sendAjaxPostRequest($url, $values);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals($first['id'], ['id']);
    }

    public function retryDoesNotRecreateADeletedPost(\IntegrationTester $I): void
    {
        $values = $this->form($I);
        $url = 'https://localhost/_inplace/post/new';
        $I->sendAjaxPostRequest($url, $values);
        $post = $I->grabJson();
        $I->assertIsArray($post);
        $I->sendAjaxPostRequest('https://localhost' . $post['action_url'], [
            'inplace_action' => 'delete', 'inplace_token' => $post['token'], 'revision' => (string)$post['revision'],
        ]);
        $I->seeResponseCodeIs(200);

        $db = $I->grabService(DbLayer::class);
        $count = (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result();
        $I->sendAjaxPostRequest($url, $values);
        $I->seeResponseCodeIs(404);
        $I->assertSame($count, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result());

        // SQLite can reuse the last deleted identifier for a different post.
        $I->sendAjaxPostRequest($url, [...$values,
            'request_id' => '22345678-1234-4567-8901-123456789012', 'title' => 'Another creation',
        ]);
        $I->seeResponseCodeIs(200);
        $I->sendAjaxPostRequest($url, $values);
        $I->seeResponseCodeIs(404);
        $I->assertSame($count + 1, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result());
    }

    public function retryReturnsTheCurrentRevisionWithoutUndoingLaterEdits(\IntegrationTester $I): void
    {
        $values = $this->form($I);
        $url = 'https://localhost/_inplace/post/new';
        $I->sendAjaxPostRequest($url, $values);
        $post = $I->grabJson();
        $I->assertIsArray($post);
        $I->sendAjaxPostRequest('https://localhost' . $post['action_url'], [...$values,
            'inplace_action' => 'edit', 'inplace_token' => $post['token'], 'revision' => (string)$post['revision'],
            'body' => '<p>Saved later.</p>',
        ]);
        $I->seeResponseCodeIs(200);
        $I->sendAjaxPostRequest($url, $values);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals(2, ['revision']);
        $I->assertJsonSubResponseEquals("<div class=\"post body\" data-post-inplace-body><p>Saved later.</p></div>\n", ['body_html']);
    }

    /** @return array<string, string> */
    private function form(\IntegrationTester $I): array
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');

        return [
            'inplace_action' => 'create',
            'inplace_token' => (string)$I->grabAttributeFrom('.site-header-shell .post-create-template input[name="inplace_token"]', 'value'),
            'request_id' => '12345678-1234-4567-8901-123456789012',
            'title' => 'Retry creation', 'body' => '<p>Original creation.</p>',
            'tags' => 'Retry tag', 'published_at' => (string)(time() - 60),
        ];
    }
}
