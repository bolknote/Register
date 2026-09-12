<?php

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentMutationSource;
use Register\Comment\CommentRepository;
use Register\Comment\ContentCommentRenderer;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Model\Comment\CommentModerationTokenManager;
use Register\Module\VisitorIdentity\VisitorIdentityRepository;
use Symfony\Component\HttpFoundation\Request;

final class CommentUndoCest
{
    public function restoresLeafAndPendingStateWithoutRepeatingMailOrReusingOldUndo(\IntegrationTester $I): void
    {
        [$comments, $content] = $this->context($I);
        /** @var CommentModerationTokenManager $tokens */
        $tokens = $I->grabService(CommentModerationTokenManager::class);
        /** @var ContentCommentRenderer $renderer */
        $renderer = $I->grabService(ContentCommentRenderer::class);

        foreach ([false, true] as $published) {
            $id = $comments->save($content, 'Author', 'private@example.test', true, 'Recover this text', '192.0.2.1', null);
            if ($published) {
                $comments->publish($id, $content->type);
                $comments->setSent($id, $content->type, true);
            }

            $before = $comments->find($id);
            $I->assertNotNull($before);
            $state = $comments->deleteRecoverably($before);
            $I->assertNotNull($state);
            $deleted = $comments->find($id);
            $I->assertNotNull($deleted, 'A leaf must remain restorable during the Undo period.');
            $I->assertTrue($deleted->deleted);
            $I->assertFalse($deleted->shown);
            $I->assertFalse($deleted->subscribed);
            $I->assertStringNotContainsString('Recover this text', $renderer->render($content, Request::create('/undo-test'), '/undo-test'));
            $token = $tokens->issueUndo('scope', $before, $state);
            $I->assertEquals($state, $tokens->readUndo($token, 'scope', $deleted));
            $I->assertTrue($comments->restoreDeleted($deleted, $state));
            $restored = $comments->find($id);
            $I->assertNotNull($restored);
            $I->assertSame($before->text, $restored->text);
            $I->assertSame($before->shown, $restored->shown);
            $I->assertSame($before->sent, $restored->sent);
            $I->assertSame($before->subscribed, $restored->subscribed);
            $I->assertFalse($comments->restoreDeleted($restored, $state));
            $next = $comments->deleteRecoverably($restored);
            $I->assertNotNull($next);
            $deletedAgain = $comments->find($id);
            $I->assertNotNull($deletedAgain);
            $I->assertNull($tokens->readUndo($token, 'scope', $deletedAgain));
        }

        $I->assertCount(0, $I->grabSubscriberMails());
    }

    public function authoritativeDeletionRevokesUndoWhilePreservingReplies(\IntegrationTester $I): void
    {
        [$comments, $content] = $this->context($I);
        /** @var CommentModerationTokenManager $tokens */
        $tokens = $I->grabService(CommentModerationTokenManager::class);
        /** @var ContentCommentRenderer $renderer */
        $renderer = $I->grabService(ContentCommentRenderer::class);
        $parent = $comments->save($content, 'Remote author', '', false, 'Remote private text', '', null);
        $comments->publish($parent, $content->type);
        $child = $comments->save($content, 'Local reader', 'reader@example.test', false, 'Surviving reply', '', $parent);
        $comments->publish($child, $content->type);

        $before = $comments->find($parent);
        $I->assertNotNull($before);
        $state = $comments->deleteRecoverably($before);
        $I->assertNotNull($state);
        $token = $tokens->issueUndo('scope', $before, $state);
        $deleted = $comments->find($parent);
        $I->assertNotNull($deleted);
        $I->assertEquals($state, $tokens->readUndo($token, 'scope', $deleted));

        $I->assertTrue($comments->tombstone($parent, $content->type, CommentMutationSource::IMPORTED));

        $tombstoned = $comments->find($parent);
        $I->assertNotNull($tombstoned);
        $I->assertTrue($tombstoned->deleted);
        $I->assertGreaterThan($state->revision, $tombstoned->modifyTime);
        $I->assertNull($tokens->readUndo($token, 'scope', $tombstoned));
        $I->assertFalse($comments->restoreDeleted($deleted, $state), 'An Undo validated before the authoritative deletion must fail its final revision guard.');

        $survivingChild = $comments->find($child);
        $I->assertNotNull($survivingChild);
        $I->assertSame($parent, $survivingChild->parentId);
        $I->assertSame('Surviving reply', $survivingChild->text);

        $html = $renderer->render($content, Request::create('/undo-test'), '/undo-test');
        $I->assertStringNotContainsString('Remote private text', $html);
        $I->assertStringContainsString('Surviving reply', $html);
    }

    public function publicUndoRequiresOriginalSessionAndKeepsReplies(\IntegrationTester $I): void
    {
        [$comments, $content] = $this->context($I);
        $parent = $comments->save($content, 'Parent', 'parent@example.test', true, 'Parent text', '192.0.2.1', null);
        $comments->publish($parent, $content->type);
        $reply = $comments->save($content, 'Child', 'child@example.test', false, 'Child text', '192.0.2.2', $parent);
        $comments->publish($reply, $content->type);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/undo-test');

        $token = (string)$I->grabAttributeFrom('[data-comment-id="' . $parent . '"] [data-moderation-action="delete"] input[name="moderation_token"]', 'value');
        $fields = ['moderation_action' => 'delete', 'target_type' => 'page', 'comment_id' => $parent, 'moderation_token' => $token, 'return_to' => '/undo-test'];
        $I->sendAjaxPostRequest('https://localhost/comment-moderate', $fields);
        $I->seeResponseCodeIs(200);

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsString($payload['undo_token']);
        $fields['moderation_action'] = 'restore';
        $fields['undo_token'] = $payload['undo_token'];
        $I->sendAjaxPostRequest('https://localhost/comment-moderate', [...$fields, 'undo_token' => 'tampered']);
        $I->seeResponseCodeIs(409);
        $I->sendAjaxPostRequest('https://localhost/comment-moderate', $fields);
        $I->seeResponseCodeIs(200);
        $I->assertSame($parent, $comments->find($reply)?->parentId);
        $I->assertTrue($comments->find($parent)?->shown);
        $I->sendAjaxPostRequest('https://localhost/comment-moderate', $fields);
        $I->seeResponseCodeIs(409);
        $I->logout();
        $I->login('guest', 'guest');
        $I->sendAjaxPostRequest('https://localhost/comment-moderate', $fields);
        $I->seeResponseCodeIs(403);
    }

    public function administrationDeletionHasUndoAndRespectsExistingPermissions(\IntegrationTester $I): void
    {
        [$comments, $content] = $this->context($I);
        $id = $comments->save($content, 'Admin undo', 'reader@example.test', true, 'Admin undo text', '192.0.2.1', null);
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list&queue=pending');

        $selector = '#admin-comment-' . $id . ' [data-admin-delete]';
        $csrf = (string)$I->grabAttributeFrom($selector, 'data-csrf-token');
        $url = 'https://localhost/_admin/index.php' . $I->grabAttributeFrom($selector, 'data-delete-url');
        $I->sendAjaxPostRequest($url, ['csrf_token' => $csrf]);
        $I->seeResponseCodeIs(200);

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertTrue($comments->find($id)?->deleted);
        $I->sendAjaxPostRequest($url, ['csrf_token' => $csrf, 'undo_token' => $payload['undo_token']]);
        $I->seeResponseCodeIs(200);

        $restored = $comments->find($id);
        $I->assertFalse($restored->deleted);
        $I->assertFalse($restored->shown);
        $I->assertFalse($restored->sent);
        $I->logout();
        $I->login('guest', 'guest');
        $I->sendAjaxPostRequest($url, ['csrf_token' => $csrf, 'undo_token' => $payload['undo_token']]);
        $I->seeResponseCodeIs(403);
    }

    public function bulkDeletionReturnsWorkingUndoForEverySelectedComment(\IntegrationTester $I): void
    {
        [$comments, $content] = $this->context($I);
        $ids = [];
        foreach (['One', 'Two'] as $name) {
            $ids[] = $comments->save($content, $name, 'reader@example.test', true, $name, '192.0.2.1', null);
        }

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list&queue=pending');

        $items = [];
        foreach ($ids as $id) {
            $items[] = ['primary_key' => ['id' => $id], 'csrf_token' => $I->grabAttributeFrom('#admin-comment-' . $id . ' [data-bulk-row-select]', 'data-row-csrf-token')];
        }

        $I->sendAjaxPostRequest('https://localhost/_admin/ajax.php?action=register_bulk_list_action', [
            'entity' => 'Comment', 'bulk_action' => 'delete',
            'csrf_token' => $I->grabAttributeFrom('[data-bulk-list]', 'data-csrf-token'),
            'items' => json_encode($items, JSON_THROW_ON_ERROR),
        ]);
        $I->seeResponseCodeIs(200);

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertCount(2, $payload['undo_items']);
        foreach ($ids as $id) {
            $I->assertTrue($comments->find($id)?->deleted);
        }

        foreach ($payload['undo_items'] as $item) {
            $I->sendAjaxPostRequest('https://localhost/_admin/' . $item['url'], $item['data']);
            $I->seeResponseCodeIs(200);
        }

        foreach ($ids as $id) {
            $I->assertFalse($comments->find($id)?->deleted);
        }
    }

    public function maintenanceKeepsLiveRepliesAndUnexpiredUndo(\IntegrationTester $I): void
    {
        [$comments, $content] = $this->context($I);
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $authorId = (int)$db->select('id')->from('users')->where("login = 'admin'")->execute()->result();
        (new VisitorIdentityRepository($db))->touchVisitor(str_repeat('a', 32), time());
        $parent = $comments->save($content, 'Parent', 'parent@example.test', false, 'Parent', '192.0.2.1', null, userId: $authorId, visitorId: str_repeat('a', 32));
        $comments->publish($parent, $content->type);
        $child = $comments->save($content, 'Child', 'child@example.test', false, 'Child', '192.0.2.2', $parent);
        $comments->publish($child, $content->type);
        $leaf = $comments->save($content, 'Leaf', 'leaf@example.test', false, 'Leaf', '192.0.2.3', null);
        $deletions = [];
        foreach ([$parent, $leaf] as $id) {
            $comment = $comments->find($id);
            $I->assertNotNull($comment);
            $deletions[$id] = $comments->deleteRecoverably($comment);
        }

        $I->assertSame(0, $comments->purgeExpiredDeletions(time() - 86400));
        $I->assertSame(0, $comments->anonymizeExpiredDeletions(time() - 86400));

        $retained = $comments->find($parent);
        $I->assertNotNull($retained);
        $I->assertSame('Parent', $retained->text);
        $I->assertSame('parent@example.test', $retained->email);
        $I->assertSame($authorId, $retained->userId);
        $I->assertSame(str_repeat('a', 32), $retained->visitorId);
        $I->assertSame(1, $comments->purgeExpiredDeletions(time() + 86400));
        $I->assertNull($comments->find($leaf));
        $I->assertSame(1, $comments->anonymizeExpiredDeletions(time() + 86400));

        $anonymized = $comments->find($parent);
        $I->assertNotNull($anonymized);
        $I->assertTrue($anonymized->deleted);
        $I->assertSame('', $anonymized->text);
        $I->assertSame('', $anonymized->name);
        $I->assertSame('', $anonymized->email);
        $I->assertSame('', $anonymized->ip);
        $I->assertNull($anonymized->userId);
        $I->assertNull($anonymized->visitorId);
        $I->assertNull($anonymized->userpicId);

        $parentDeletion = $deletions[$parent];
        $I->assertNotNull($parentDeletion);
        $I->assertFalse($comments->restoreDeleted($retained, $parentDeletion), 'An Undo checked before anonymization must fail its final revision guard.');
        $I->assertSame(0, $comments->anonymizeExpiredDeletions(time() + 86400));

        $survivingChild = $comments->find($child);
        $I->assertNotNull($survivingChild);
        $I->assertSame($parent, $survivingChild->parentId);
        $I->assertSame('Child', $survivingChild->text);
        $I->assertSame('child@example.test', $survivingChild->email);
    }

    /** @return array{CommentRepository, ContentId} */
    private function context(\IntegrationTester $I): array
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $db->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => "'page'", 'parent_id' => '1', 'slug_scope' => "'root'",
            'title' => "'Undo test'", 'excerpt' => "''", 'body' => "'Test discussion'",
            'created_at' => ':now', 'published_at' => ':now', 'updated_at' => ':now',
            'revision' => '1', 'sort_order' => '0', 'published' => '1', 'featured' => '0',
            'comments_enabled' => '1', 'slug' => "'undo-test'", 'template' => "'site.php'",
        ])->execute(['now' => time()]);
        $contentId = ContentId::page((int)$db->insertId());
        /** @var CommentRepository $comments */
        $comments = $I->grabService(CommentRepository::class);

        return [$comments, $contentId];
    }
}
