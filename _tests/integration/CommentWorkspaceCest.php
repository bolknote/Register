<?php

declare(strict_types = 1);

namespace integration;

use Register\Auth\PublicAuthRepository;
use Register\Comment\Antispam\SpamFeedbackService;
use Register\Comment\CommentRepository;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Comment\CommentHtml;
use Register\Core\Pdo\DbLayer;

final class CommentWorkspaceCest
{
    public function testQueuesRenderReadableCommentsAndPerformExplicitDecisions(\IntegrationTester $I): void
    {
        [$comments, $contentId] = $this->context($I);
        $parent = $comments->save($contentId, 'Published author', 'parent@example.test', false, 'The original question', '192.0.2.1', null);
        $comments->publish($parent, $contentId->type);
        $pending = $comments->save($contentId, 'Pending author', 'pending@example.test', false, CommentHtml::sanitizeForStorage('<p>A <strong>readable reply</strong>.</p>'), '192.0.2.2', $parent);
        $hidden = $comments->save($contentId, 'Hidden author', 'hidden@example.test', false, 'Hidden answer', '192.0.2.3', null);
        $comments->setSent($hidden, $contentId->type, true);
        $spam = $comments->save($contentId, 'Spam author', 'spam@example.test', false, 'Spam answer', '192.0.2.4', null);
        /** @var SpamFeedbackService $feedback */
        $feedback = $I->grabAdminService(SpamFeedbackService::class);
        $feedback->markSpam($spam);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list');
        $I->seeResponseCodeIs(200);
        $I->seeElement('.comment-queues a[aria-current="page"][href*="queue=pending"]');
        $I->see('Pending author', '.comment-list');
        $I->dontSee('Published author', '.comment-author');
        $I->dontSee('Hidden author', '.comment-list');
        $I->dontSee('Spam author', '.comment-list');
        $I->see('readable reply', '.comment-body strong');
        $I->see('The original question', '.comment-parent');
        $I->assertStringNotContainsString('register-comment-html', $I->grabResponse());
        $I->dontSeeElement('.comment-list input[name="shown"]');
        $I->seeElement('.comment-context a[href$="#comments-title"]');

        $this->decide($I, $pending, 'ham');
        $publishedComment = $comments->find($pending);
        $I->assertNotNull($publishedComment);
        $I->assertTrue($publishedComment->shown);
        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list&queue=published&apply_filter=0');
        $I->see('Pending author', '.comment-list');
        $I->dontSeeElement('#admin-comment-' . $pending . ' .list-action-link-ham');
        $this->decide($I, $pending, 'reject');
        $hiddenComment = $comments->find($pending);
        $I->assertNotNull($hiddenComment);
        $I->assertFalse($hiddenComment->shown);
        $I->assertTrue($hiddenComment->sent);

        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list&queue=hidden&apply_filter=0');
        $I->see('Pending author', '.comment-list');
        $I->see('Hidden author', '.comment-list');
        $I->dontSee('Spam author', '.comment-list');
        $this->decide($I, $pending, 'spam');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list&queue=spam&apply_filter=0');
        $I->see('Pending author', '.comment-list');
        $I->see('Spam author', '.comment-list');
        $I->see('Not spam — publish', '#admin-comment-' . $pending . ' .comment-actions');
        $I->dontSeeElement('#admin-comment-' . $pending . ' .list-action-link-spam');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list&comment_id=' . $parent . '&apply_filter=0');
        $I->see('Published author', '.comment-list');
        $I->dontSee('Pending author', '.comment-list');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list&queue=pending&content_id=' . $contentId->value . '&search=Pending%20author&apply_filter=0');
        $I->seeElement('.comment-queues a[href*="queue=spam"][href*="content_id=' . $contentId->value . '"][href*="search=Pending"]');
        $I->see('0', '.comment-queues a[aria-current="page"] .comment-queue-count');
        $I->see('1', '.comment-queues a[href*="queue=spam"] .comment-queue-count');
        $I->see('Nothing matches these filters');
        $I->dontSee('No comments awaiting review');

        $spamQueueUrl = $I->grabAttributeFrom('.comment-queues a[href*="queue=spam"]', 'href');
        $I->assertNotNull($spamQueueUrl);
        $I->amOnPage('https://localhost/_admin/index.php' . $spamQueueUrl);
        $I->see('Pending author', '.comment-list');
        $I->dontSee('Spam author', '.comment-list');
    }

    public function testReadOnlyReaderCannotSeeHiddenParentOrPrivateData(\IntegrationTester $I): void
    {
        [$comments, $contentId] = $this->context($I);
        $parent = $comments->save($contentId, 'Private parent', 'private-parent@example.test', false, 'Private parent text', '192.0.2.55', null);
        $comments->publish($parent, $contentId->type);
        $child = $comments->save($contentId, 'Visible child', 'private-child@example.test', false, 'Public child text', '192.0.2.56', $parent);
        $comments->publish($child, $contentId->type);
        $comments->hide($parent, $contentId->type);

        $I->login('guest', 'guest');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list&queue=all');
        $I->seeResponseCodeIs(200);
        $I->see('Visible child', '.comment-list');
        $I->dontSee('Private parent');
        $I->dontSee('private-child@example.test');
        $I->dontSee('192.0.2.56');
        $I->dontSeeElement('.comment-parent');
        $I->dontSeeElement('.comment-actions [data-list-action]');
        $I->dontSeeElement('[data-bulk-row-select]');
        $I->dontSeeElement('[data-admin-delete]');
    }

    public function testRepublishingApprovedCommentDoesNotNotifySubscribersAgain(\IntegrationTester $I): void
    {
        [$comments, $contentId] = $this->context($I);
        /** @var PublicAuthRepository $identities */
        $identities = $I->grabService(PublicAuthRepository::class);
        $subscriberId = $identities->findOrCreateIdentity('email', 'subscriber@example.test', 'subscriber@example.test', 'Subscriber');
        $subscription = $comments->save($contentId, 'Subscriber', 'subscriber@example.test', true, 'Subscribed comment', '192.0.2.8', null, $subscriberId);
        $comments->publish($subscription, $contentId->type);
        $comments->setSent($subscription, $contentId->type, true);

        $commentId = $comments->save($contentId, 'Reply author', 'reply@example.test', false, 'Comment approved once', '192.0.2.9', null);

        // The public container captures actual deliveries instead of queueing mail.
        /** @var SpamFeedbackService $feedback */
        $feedback = $I->grabService(SpamFeedbackService::class);
        $I->assertTrue($feedback->markHam($commentId, $contentId->type));
        $approved = $comments->find($commentId);
        $I->assertNotNull($approved);
        $I->assertTrue($approved->shown);
        $I->assertTrue($approved->sent);
        $I->assertCount(1, $I->grabSubscriberMails());

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list&queue=published&apply_filter=0');
        $this->decide($I, $commentId, 'reject');
        $hidden = $comments->find($commentId);
        $I->assertNotNull($hidden);
        $I->assertFalse($hidden->shown);
        $I->assertTrue($hidden->sent);

        $I->assertTrue($feedback->markHam($commentId, $contentId->type));

        $republished = $comments->find($commentId);
        $I->assertNotNull($republished);
        $I->assertTrue($republished->shown);
        $I->assertTrue($republished->sent);
        $I->assertCount(1, $I->grabSubscriberMails());
    }

    public function testModeratorBulkHideMatchesIndividualHideWithoutDeletePermission(\IntegrationTester $I): void
    {
        [$comments, $contentId] = $this->context($I);
        $ids = [];
        foreach (['First', 'Second'] as $name) {
            $id = $comments->save($contentId, $name, 'author@example.test', false, $name . ' published comment', '192.0.2.7', null);
            $comments->publish($id, $contentId->type);
            $ids[] = $id;
        }

        $I->login('moderator', 'moderator');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list&queue=published');

        $items = [];
        foreach ($ids as $id) {
            $token = $I->grabAttributeFrom('#admin-comment-' . $id . ' [data-bulk-row-select]', 'data-row-csrf-token');
            $I->assertNotEmpty($token);
            $items[] = ['primary_key' => ['id' => $id], 'csrf_token' => $token];
        }

        $I->sendAjaxPostRequest('https://localhost/_admin/ajax.php?action=register_bulk_list_action', [
            'entity' => 'Comment',
            'bulk_action' => 'reject',
            'csrf_token' => $I->grabAttributeFrom('[data-bulk-list]', 'data-csrf-token'),
            'items' => json_encode($items, JSON_THROW_ON_ERROR),
        ]);
        $I->seeResponseCodeIs(200);
        foreach ($ids as $id) {
            $comment = $comments->find($id);
            $I->assertNotNull($comment);
            $I->assertFalse($comment->shown);
            $I->assertTrue($comment->sent);
        }
    }

    private function decide(\IntegrationTester $I, int $id, string $action): void
    {
        $selector = '#admin-comment-' . $id . ' .list-action-link-' . $action;
        $url = $I->grabAttributeFrom($selector, 'href');
        $token = $I->grabAttributeFrom($selector, 'data-csrf-token');
        $I->assertNotNull($url);
        $I->sendPost('https://localhost/_admin/index.php' . $url, ['csrf_token' => $token]);
        $I->seeResponseCodeIs(200);
    }

    /** @return array{CommentRepository, ContentId} */
    private function context(\IntegrationTester $I): array
    {
        /** @var DbLayer $db */
        $db = $I->grabAdminService(DbLayer::class);
        $content = $db->select('id, content_type')->from(ContentSchema::TABLE_NAME)->orderBy('id')->limit(1)->execute()->fetchAssoc();
        if (!\is_array($content)) {
            throw new \LogicException('Missing content fixture.');
        }

        /** @var CommentRepository $comments */
        $comments = $I->grabAdminService(CommentRepository::class);

        return [$comments, new ContentId(ContentType::from((string)$content['content_type']), (int)$content['id'])];
    }
}
