<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentPublicationScheduler;
use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;

final class PublicationRevisionCest
{
    public function scheduledPageCannotBeUnpublishedByAnOlderEditor(\IntegrationTester $I): void
    {
        [$id, $url, $values] = $this->draftPage($I);
        $values['scheduled_at'] = date('Y-m-d\TH:i', time() + 3600);
        $values['_publication_state'] = 'scheduled';
        $I->sendAjaxPostRequest($url, $values);
        $I->seeResponseCodeIs(200);
        $I->amOnPage($url);

        $older = $I->grabFormValues('form[name="article-form"]');
        $before = $this->stored($I, $id);
        $scheduler = $I->grabAdminService(ContentPublicationScheduler::class);
        $I->assertSame(1, $scheduler->publishDue((int)$before['scheduled_at']));
        $published = $this->stored($I, $id);
        $I->assertSame(1, (int)$published['published']);
        $I->assertSame(0, (int)$published['scheduled_at']);

        $I->sendAjaxPostRequest($url, [...$older, 'body' => '<p>Unsaved page changes.</p>']);
        $I->seeResponseCodeIs(422);
        $I->assertJsonSubResponseEquals(false, ['success']);
        $I->assertSame($published, $this->stored($I, $id));
        $I->assertSame((int)$before['revision'] + 1, (int)$published['revision']);
        $I->assertSame(0, $scheduler->publishDue((int)$before['scheduled_at']));
        $I->assertSame($published, $this->stored($I, $id));
        $this->retryPage($I, $url, $id, (int)$published['revision'], true);
    }

    public function pagePublicationFromAnotherEditorRejectsTheOlderForm(\IntegrationTester $I): void
    {
        [$id, $url, $older] = $this->draftPage($I);
        $I->sendAjaxPostRequest($url, [...$older, 'published' => '1', '_publication_state' => 'published']);
        $I->seeResponseCodeIs(200);

        $published = $this->stored($I, $id);
        $I->amOnPage($url);
        $I->sendAjaxPostRequest($url, $I->grabFormValues('form[name="article-form"]'));
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$published['revision'], ['revision']);
        // No body edit is needed: the older form still submits its draft state.
        $I->sendAjaxPostRequest($url, $older);
        $I->seeResponseCodeIs(422);
        $I->assertJsonSubResponseEquals(false, ['success']);
        $I->assertSame($published, $this->stored($I, $id));
        $I->assertSame((int)$older['revision'] + 1, (int)$published['revision']);
        $this->retryPage($I, $url, $id, (int)$published['revision'], true);
    }

    public function bulkPagePublicationRejectsTheOlderFormInBothDirections(\IntegrationTester $I): void
    {
        [$id, $url] = $this->draftPage($I);
        foreach ([true, false] as $published) {
            $I->amOnPage($url);
            $older = $I->grabFormValues('form[name="article-form"]');
            $this->bulkPublish($I, 'Article', $id, $published);
            $changed = $this->stored($I, $id);
            $I->assertSame($published, (bool)$changed['published']);

            $I->sendAjaxPostRequest($url, $older);
            $I->seeResponseCodeIs(422);
            $I->assertJsonSubResponseEquals(false, ['success']);
            $I->assertSame($changed, $this->stored($I, $id));
            $I->assertSame((int)$older['revision'] + 1, (int)$changed['revision']);
            $this->bulkPublish($I, 'Article', $id, $published, 0);
            $I->assertSame($changed, $this->stored($I, $id));
            $this->retryPage($I, $url, $id, (int)$changed['revision'], $published);
        }
    }

    public function scheduledPostRejectsChangesFromItsOlderInlineEditor(\IntegrationTester $I): void
    {
        $scheduledAt = time() + 3600;
        [$id, $url] = $this->createPost($I, $scheduledAt);
        $I->amOnPage($url);
        $older = $I->grabFormValues('.post-card[data-post-id="' . $id . '"] > .post-inplace-edit-form');
        $scheduler = $I->grabService(ContentPublicationScheduler::class);
        $I->assertSame(1, $scheduler->publishDue($scheduledAt));
        $published = $this->stored($I, $id);

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $id, [
            ...$older, 'body' => '<p>Unsaved inline changes.</p>',
        ]);
        $I->seeResponseCodeIs(409);
        $I->assertJsonSubResponseEquals(false, ['success']);
        $I->assertSame($published, $this->stored($I, $id));
        $I->assertSame((int)$older['revision'] + 1, (int)$published['revision']);
        $I->assertSame(1, (int)$published['published']);
        $I->assertSame(0, (int)$published['scheduled_at']);
        $this->rejectOlderDelete($I, $id, $older);
        $I->assertSame($published, $this->stored($I, $id));
        $this->retryPost($I, $url, $id, (int)$published['revision'], true);
    }

    public function bulkPostPublicationRejectsChangesFromItsOlderInlineEditor(\IntegrationTester $I): void
    {
        [$id, $url] = $this->createPost($I, time() - 3600);
        foreach ([false, true] as $published) {
            $I->amOnPage($url);
            $older = $I->grabFormValues('.post-card[data-post-id="' . $id . '"] > .post-inplace-edit-form');
            $this->bulkPublish($I, 'BlogPost', $id, $published);
            $changed = $this->stored($I, $id);

            $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $id, [
                ...$older, 'body' => '<p>Unsaved inline changes.</p>',
            ]);
            $I->seeResponseCodeIs(409);
            $I->assertJsonSubResponseEquals(false, ['success']);
            $I->assertSame($changed, $this->stored($I, $id));
            $I->assertSame((int)$older['revision'] + 1, (int)$changed['revision']);
            $I->assertSame($published, (bool)$changed['published']);
            $this->bulkPublish($I, 'BlogPost', $id, $published, 0);
            $I->assertSame($changed, $this->stored($I, $id));
            $this->rejectOlderDelete($I, $id, $older);
            $I->assertSame($changed, $this->stored($I, $id));
            $this->retryPost($I, $url, $id, (int)$changed['revision'], $published);
        }
    }

    /** @return array{int, string, array<string, mixed>} */
    private function draftPage(\IntegrationTester $I): array
    {
        $I->login('admin', 'admin');
        $db = $I->grabAdminService(DbLayer::class);
        $id = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'page'")->andWhere('parent_id IS NULL')->execute()->result();
        $url = 'https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $id;
        $I->amOnPage($url);

        $values = $I->grabFormValues('form[name="article-form"]');
        unset($values['published']);
        $I->sendAjaxPostRequest($url, [...$values,
            'body' => '<p>Original page.</p>', 'meta_description' => 'Original page.',
            'scheduled_at' => '', '_publication_state' => 'draft',
        ]);
        $I->seeResponseCodeIs(200);
        $I->amOnPage($url);
        return [$id, $url, $I->grabFormValues('form[name="article-form"]')];
    }

    /** @return array{int, string} */
    private function createPost(\IntegrationTester $I, int $publishedAt): array
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');

        $token = $I->grabAttributeFrom('.site-header-shell .post-create-template input[name="inplace_token"]', 'value');
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
            'inplace_action' => 'create', 'inplace_token' => $token, 'revision' => '0',
            'title' => 'Publication revision', 'body' => '<p>Original post.</p>',
            'tags' => '', 'published_at' => (string)$publishedAt,
        ]);
        $I->seeResponseCodeIs(200);

        $created = $I->grabJson();
        $I->assertIsArray($created);
        $I->assertArrayHasKey('id', $created);
        $I->assertArrayHasKey('url', $created);
        return [(int)$created['id'], 'https://localhost' . $created['url']];
    }

    private function bulkPublish(\IntegrationTester $I, string $entity, int $id, bool $published, int $updated = 1): void
    {
        $I->amOnPage('https://localhost/_admin/index.php?entity=' . $entity . '&action=list');
        $token = $I->grabAttributeFrom('[data-bulk-list]', 'data-csrf-token');
        $I->sendPost('https://localhost/_admin/ajax.php?action=register_bulk_list_action', [
            'entity' => $entity, 'bulk_action' => $published ? 'publish' : 'unpublish',
            'csrf_token' => $token,
            'items' => json_encode([['primary_key' => ['id' => $id], 'csrf_token' => '']], JSON_THROW_ON_ERROR),
        ]);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals($updated, ['updated']);
    }

    private function retryPage(\IntegrationTester $I, string $url, int $id, int $revision, bool $published): void
    {
        $I->amOnPage($url);
        $values = $I->grabFormValues('form[name="article-form"]');
        $body = '<p>Page changes based on revision ' . $revision . '.</p>';
        $I->sendAjaxPostRequest($url, [...$values, 'body' => $body]);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals($revision + 1, ['revision']);

        $stored = $this->stored($I, $id);
        $I->assertSame($body, $stored['body']);
        $I->assertSame($published, (bool)$stored['published']);
    }

    private function retryPost(\IntegrationTester $I, string $url, int $id, int $revision, bool $published): void
    {
        $I->amOnPage($url);
        $values = $I->grabFormValues('.post-card[data-post-id="' . $id . '"] > .post-inplace-edit-form');
        $body = '<p>Post changes based on revision ' . $revision . '.</p>';
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $id, [
            ...$values, 'body' => $body, 'published_at' => (string)(time() - 60),
        ]);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals($revision + 1, ['revision']);

        $stored = $this->stored($I, $id);
        $I->assertSame($body, $stored['body']);
        $I->assertSame($published, (bool)$stored['published']);
    }

    /** @param array<string, mixed> $older */
    private function rejectOlderDelete(\IntegrationTester $I, int $id, array $older): void
    {
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $id, [...$older, 'inplace_action' => 'delete']);
        $I->seeResponseCodeIs(409);
        $I->assertJsonSubResponseEquals(false, ['success']);
    }

    /** @return array<string, mixed> */
    private function stored(\IntegrationTester $I, int $id): array
    {
        $db = $I->grabAdminService(DbLayer::class);
        $row = $db->select('title, body, published, published_at, scheduled_at, revision')
            ->from(ContentSchema::TABLE_NAME)->where('id = :id')->setParameter('id', $id)->execute()->fetchAssoc();
        $I->assertIsArray($row);
        return $row;
    }
}
