<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\Tag;
use Register\Content\TagRepository;
use Register\Core\Pdo\DbLayer;
use Register\Live\LiveUpdateRepository;

final class EditorTagsCest
{
    public function creatingAPostRejectsOversizedTagsAndAcceptsTheUnicodeBoundary(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');

        $db = $I->grabService(DbLayer::class);
        $count = (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result();
        $tagCount = (int)$db->select('COUNT(*)')->from('tags')->execute()->result();
        $values = [
            'inplace_action' => 'create',
            'inplace_token' => (string)$I->grabAttributeFrom('.site-header-shell .post-create-template input[name="inplace_token"]', 'value'),
            'title' => 'Tag length boundary', 'body' => '<p>New post body.</p>',
            'published_at' => (string)(time() - 60), 'tags' => 'New tag, ' . str_repeat('Д', 192),
        ];
        $url = 'https://localhost/_inplace/post/new';
        $I->sendAjaxPostRequest($url, $values);
        $I->seeResponseCodeIs(422);
        $I->assertSame($count, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result());
        $I->assertSame($tagCount, (int)$db->select('COUNT(*)')->from('tags')->execute()->result());

        $I->sendAjaxPostRequest($url, [...$values, 'tags' => str_repeat('Д', 191)]);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals(str_repeat('Д', 191), ['tags', 0, 'name']);
        $I->assertSame($count + 1, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result());
    }

    public function editingAPostRejectsOversizedTagsWithoutChangingContent(\IntegrationTester $I): void
    {
        [$id, $url, $values] = $this->post($I);
        $db = $I->grabService(DbLayer::class);
        $before = $this->storedPost($I, $id);
        $tagCount = (int)$db->select('COUNT(*)')->from('tags')->execute()->result();
        $I->sendAjaxPostRequest($url, [...$values, 'body' => '<p>Retry this text.</p>', 'tags' => 'New tag, ' . str_repeat('Д', 192)]);
        $I->seeResponseCodeIs(422);
        $I->assertSame($before, $this->storedPost($I, $id));
        $I->assertSame($tagCount, (int)$db->select('COUNT(*)')->from('tags')->execute()->result());

        $I->sendAjaxPostRequest($url, [...$values, 'body' => '<p>Retry this text.</p>', 'tags' => str_repeat('Д', 191)]);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$values['revision'] + 1, ['revision']);
        $I->assertSame('<p>Retry this text.</p>', $this->storedPost($I, $id)['body']);
        $I->assertSame([str_repeat('Д', 191)], $this->storedPost($I, $id)['tags']);
    }

    public function equivalentPostTagsDoNotCreateRevisionsOrConflicts(\IntegrationTester $I): void
    {
        [$id, $url, $values] = $this->post($I);
        $before = $this->storedPost($I, $id);
        $updates = $I->grabService(LiveUpdateRepository::class);
        $cursor = $updates->currentCursor();
        $equivalent = [...$values, 'tags' => ' #mixed, ДВА, Mixed, два '];
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $I->sendAjaxPostRequest($url, $equivalent);
            $I->seeResponseCodeIs(200);
            $I->assertJsonSubResponseEquals((int)$values['revision'], ['revision']);
            $I->assertSame($before, $this->storedPost($I, $id));
            $I->assertSame($cursor, $updates->currentCursor());
        }

        $I->sendAjaxPostRequest($url, [...$values, 'body' => '<p>From another open tab.</p>']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$values['revision'] + 1, ['revision']);
        $I->sendAjaxPostRequest($url, [...$values, 'revision' => (string)((int)$values['revision'] + 1),
            'body' => '<p>From another open tab.</p>', 'tags' => 'два, MIXED']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$values['revision'] + 2, ['revision']);
        $I->assertSame(['Два', 'Mixed'], $this->storedPost($I, $id)['tags']);
    }

    public function pageTagLengthIsValidatedBeforeWritingContent(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $db = $I->grabAdminService(DbLayer::class);
        $id = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'page'")->andWhere('parent_id IS NULL')->execute()->result();
        $url = 'https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $id;
        $I->amOnPage($url);
        $values = $I->grabFormValues('form[name="article-form"]');
        $before = $db->select('*')->from(ContentSchema::TABLE_NAME)->where('id = :id')->setParameter('id', $id)->execute()->fetchAssoc();
        $tagCount = (int)$db->select('COUNT(*)')->from('tags')->execute()->result();
        $I->sendAjaxPostRequest($url, [...$values, 'body' => '<p>Retry this page.</p>', 'tags' => 'New tag, ' . str_repeat('Д', 192)]);
        $I->seeResponseCodeIs(422);

        $payload = $I->grabJson();
        $I->assertIsArray($payload);
        $I->assertStringContainsString('191', $payload['field_errors']['tags'][0]);
        $I->assertSame($before, $db->select('*')->from(ContentSchema::TABLE_NAME)->where('id = :id')->setParameter('id', $id)->execute()->fetchAssoc());
        $I->assertSame($tagCount, (int)$db->select('COUNT(*)')->from('tags')->execute()->result());

        // Validate each name, allowing a combined list longer than the single-name limit.
        $I->sendAjaxPostRequest($url, [...$values, 'body' => '<p>Retry this page.</p>',
            'tags' => ' ' . str_repeat('Д', 191) . ' , ' . str_repeat('A', 191) . ' ']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$values['revision'] + 1, ['revision']);
        $I->assertSame('<p>Retry this page.</p>', $db->select('body')->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $id)->execute()->result());
    }

    public function tagManagementRejectsNamesLargerThanTheStorageColumn(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $id = $I->grabAdminService(TagRepository::class)->findOrCreateIdsByNames(['Managed tag'])[0];
        $url = 'https://localhost/_admin/index.php?entity=Tag&action=edit&id=' . $id;
        $I->amOnPage($url);
        $values = $I->grabFormValues('.edit-content > form');
        $db = $I->grabAdminService(DbLayer::class);
        $tagCount = (int)$db->select('COUNT(*)')->from('tags')->execute()->result();
        $I->sendAjaxPostRequest($url, [...$values, 'name' => str_repeat('Д', 192), 'url' => 'short-valid-url']);
        $I->seeResponseCodeIs(422);

        $payload = $I->grabJson();
        $I->assertIsArray($payload);
        $I->assertStringContainsString('191', $payload['field_errors']['name'][0]);
        $I->assertSame($tagCount, (int)$db->select('COUNT(*)')->from('tags')->execute()->result());
        $I->assertSame('Managed tag', $db->select('name')->from('tags')
            ->where('id = :id')->setParameter('id', $id)->execute()->result());
        $I->sendAjaxPostRequest($url, [...$values, 'name' => str_repeat('Д', 191), 'url' => 'short-valid-url']);
        $I->seeResponseCodeIs(200);
        $I->assertSame(str_repeat('Д', 191), $db->select('name')->from('tags')
            ->where('url = :url')->setParameter('url', 'short-valid-url')->execute()->result());
    }

    /** @return array{int, string, array<string, mixed>} */
    private function post(\IntegrationTester $I): array
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
            'inplace_action' => 'create',
            'inplace_token' => (string)$I->grabAttributeFrom('.site-header-shell .post-create-template input[name="inplace_token"]', 'value'),
            'title' => 'Tag revision fixture', 'body' => '<p>Original post.</p>',
            'published_at' => (string)(time() - 60), 'tags' => 'Mixed, Два',
        ]);
        $I->seeResponseCodeIs(200);

        $payload = $I->grabJson();
        $I->assertIsArray($payload);
        $id = (int)$payload['id'];
        $I->amOnPage('https://localhost' . $payload['url']);

        return [$id, 'https://localhost/_inplace/post/' . $id,
            $I->grabFormValues('.post-card[data-post-id="' . $id . '"] > .post-inplace-edit-form')];
    }

    /** @return array<string, mixed> */
    private function storedPost(\IntegrationTester $I, int $id): array
    {
        $stored = $I->grabService(DbLayer::class)->select('title, body, revision, updated_at')->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $id)->execute()->fetchAssoc();
        $I->assertIsArray($stored);
        $contentId = ContentId::post($id);
        $stored['tags'] = array_map(static fn(Tag $tag): string => $tag->name,
            $I->grabService(TagRepository::class)->findForContent([$contentId])[(string)$contentId]);

        return $stored;
    }
}
