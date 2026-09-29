<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;

final class AdminEditorValidationCest
{
    public function invalidPageFieldsReturnSpecificErrorsAndKeepStoredContent(\IntegrationTester $I): void
    {
        $db = $I->grabAdminService(DbLayer::class);
        $original = $db->select('id, title, body, revision')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'page'")->andWhere('parent_id IS NULL')->execute()->fetchAssoc();
        $I->assertIsArray($original);
        $url = 'https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $original['id'];
        $I->login('admin', 'admin');
        $I->amOnPage($url);

        $values = $I->grabFormValues('form[name="article-form"]');
        $invalid = [...$values, 'title' => str_repeat('T', 256), 'tags' => 'invalid+tag', 'body' => '<p>Unsaved body.</p>'];
        $I->sendAjaxPostRequest($url, $invalid);
        $I->seeResponseCodeIs(422);

        $payload = $I->grabJson();
        $I->assertIsArray($payload);
        $I->assertFalse($payload['success']);
        $I->assertSame([], $payload['errors']);
        $I->assertCount(1, $payload['field_errors']['title']);
        $I->assertStringContainsString('255', $payload['field_errors']['title'][0]);
        $I->assertSame(['Tags must contain only letters, numbers and spaces.'], $payload['field_errors']['tags']);
        $I->assertSame($original, $db->select('id, title, body, revision')->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $original['id'])->execute()->fetchAssoc());

        $I->sendAjaxPostRequest($url, [...$invalid, 'title' => 'Corrected title', 'tags' => 'valid']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals(true, ['success']);
        $I->assertJsonSubResponseEquals((int)$original['revision'] + 1, ['revision']);

        $stored = $db->select('title, body')->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $original['id'])->execute()->fetchAssoc();
        $I->assertIsArray($stored);
        $I->assertSame('Corrected title', $stored['title']);
        $I->assertSame('<p>Unsaved body.</p>', $stored['body']);
    }
}
