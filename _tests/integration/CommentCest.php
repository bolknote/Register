<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentSchema;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Comment\CommentHtml;
use Register\Core\Pdo\DbLayer;
use Register\Module\VisitorIdentity\Manifest as VisitorIdentityManifest;
use Register\Module\VisitorIdentity\VisitorIdentityManager;

class CommentCest
{
    public function testPreviewAcceptsAnEmptyParentId(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertArticle($dbLayer);
        $countBefore = (int)$dbLayer->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->execute()->result();

        $I->sendPost('https://localhost/thread-test', [
            'name'         => 'Preview author',
            'email'        => 'preview@example.test',
            'text'         => 'Top-level preview text',
            'parent_id'    => '',
            'reply_number' => '0',
            'reply_name'   => '',
            'preview'      => '1',
        ]);

        $I->seeResponseCodeIs(200);
        $I->see('Top-level preview text');
        $I->seeElement('.comment-preview-item');
        $I->seeElement('.comment-preview-item .comment-userpic-fallback');
        $I->dontSeeElement('.comment-preview-item .comment-reply');
        $I->assertSame($countBefore, (int)$dbLayer->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->execute()->result());
    }

    public function testSavesATopLevelCommentWithAnEmptyParentId(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertArticle($dbLayer);

        $I->sendPost('https://localhost/thread-test', [
            'name'      => 'Top-level author',
            'email'     => 'top-level@example.test',
            'text'      => 'Top-level comment text',
            'parent_id' => '',
        ]);

        $I->seeResponseCodeIs(302);

        $comment = $dbLayer
            ->select('parent_id', 'text')
            ->from(CommentSchema::TABLE_NAME)
            ->where('nick = :nick')->setParameter('nick', 'Top-level author')
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($comment);
        $I->assertNull($comment['parent_id']);
        $I->assertSame('Top-level comment text', CommentHtml::plainText((string)$comment['text']));
    }

    public function testNonExistentPage(\IntegrationTester $I): void
    {
        $I->sendPost('https://localhost/some-non-existent-url', [
            'name'  => 'Name',
            'email' => 'a@example.com',
            'text'  => 'text',
        ]);
        $I->see('The destination page cannot be detected due to an error');
    }

    public function testAuthenticatedOwnerUsesAccountIdentityInTheRichEditor(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var VisitorIdentityManager $identityManager */
        $identityManager = $I->grabService(VisitorIdentityManager::class);
        $this->insertArticle($dbLayer);
        $dbLayer
            ->update('users')
            ->set('email', "''")
            ->where("login = 'admin'")
            ->execute()
        ;

        $I->sendJson('https://localhost/_visitor/resolve', [
            'trackPage' => false,
        ], headers: ['Origin' => 'https://localhost']);
        $resolved = $I->grabJson();
        $I->assertIsArray($resolved);
        $visitorId = $identityManager->visitorIdFromToken((string)($resolved['token'] ?? ''));
        $I->assertNotNull($visitorId);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/thread-test');
        $I->seeElement('#comment-form [data-comment-editor]');
        $I->seeElement('#comment-form .comment-editor-toolbar');
        $I->see('Commenting as', '.comment-public-auth');
        $I->see('admin', '.comment-public-auth');
        $I->dontSeeElement('#comment-form [data-comment-guest-identity]');
        $I->seeElement('#comment-form .comment-options input[name="subscribed"]');
        $I->dontSeeElement('#comment-form input[name="show_email"]');
        $I->dontSeeElement('#comment-form .comment-preview');

        $I->sendPost('https://localhost/thread-test', [
            'name'  => 'Spoofed name',
            'email' => 'spoofed@example.test',
            'text'  => '<p><strong>Owner</strong> comment</p>',
        ]);
        $I->seeResponseCodeIs(302);

        $comment = $dbLayer
            ->select('nick', 'email', 'user_id', 'visitor_id', 'text')
            ->from(CommentSchema::TABLE_NAME)
            ->where('nick = :nick')->setParameter('nick', 'admin')
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($comment);
        $I->assertSame('admin', $comment['nick']);
        $I->assertSame('', $comment['email']);

        $userId = (int)$dbLayer->select('id')->from('users')->where("login = 'admin'")->execute()->result();
        $I->assertSame($userId, (int)$comment['user_id']);
        $I->assertSame($visitorId, $comment['visitor_id']);
        $I->assertSame(1, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(VisitorIdentityManifest::USER_LINK_TABLE)
            ->where('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->andWhere('user_id = :user_id')->setParameter('user_id', $userId)
            ->execute()
            ->result());
        $I->assertSame('Owner comment', CommentHtml::plainText((string)$comment['text']));
        $I->assertStringContainsString('<strong>Owner</strong>', (string)$comment['text']);
    }

    public function testSavesAndRendersAReplyAsARealBranch(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $articleId = $this->insertArticle($dbLayer);
        $parentId  = $this->insertComment($dbLayer, $articleId, 'Parent', 'reader@example.test');

        $I->sendPost('https://localhost/thread-test', [
            'name'         => 'Reply author',
            'email'        => 'reply@example.test',
            'text'         => 'A nested reply',
            'parent_id'    => (string)$parentId,
            'reply_number' => '1',
            'reply_name'   => 'Parent',
        ]);
        $I->seeResponseCodeIs(302);

        $reply = $dbLayer
            ->select('id', 'parent_id', 'text')
            ->from(CommentSchema::TABLE_NAME)
            ->where('nick = :nick')->setParameter('nick', 'Reply author')
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($reply);
        $I->assertSame($parentId, (int)$reply['parent_id']);
        $I->assertSame('A nested reply', CommentHtml::plainText((string)$reply['text']));

        $I->amOnPage('https://localhost/thread-test');
        $I->seeElement(
            '[data-comment-id="' . $parentId . '"] .comment-children [data-comment-id="' . (int)$reply['id'] . '"]'
        );
        $I->seeElement('[data-comment-id="' . $parentId . '"] .comment-reply');
    }

    public function testRendersAStoredUserpicWithoutAPlaceholder(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $articleId = $this->insertArticle($dbLayer);

        $dbLayer
            ->insert('userpics')
            ->setValue('storage_key', "'userpics/example.jpg'")
            ->setValue('content_hash', ':content_hash')->setParameter('content_hash', str_repeat('a', 64))
            ->setValue('mime_type', "'image/jpeg'")
            ->setValue('width', '80')
            ->setValue('height', '80')
            ->setValue('byte_size', '1024')
            ->setValue('created_time', ':time')->setParameter('time', time())
            ->execute()
        ;
        $userpicId = (int)$dbLayer->insertId();
        $commentId = $this->insertComment($dbLayer, $articleId, 'Userpic author', 'avatar@example.test', $userpicId);

        $I->amOnPage('https://localhost/thread-test');
        $I->seeElement('[data-comment-id="' . $commentId . '"].has-userpic');
        $I->seeElement(
            '[data-comment-id="' . $commentId . '"] .comment-userpic img[src="/_tests/_output/images/userpics/example.jpg"]'
        );
        $I->dontSeeElement('[data-comment-id="' . $commentId . '"] .comment-userpic-fallback');
    }

    public function testRendersInitialsWhenACommentHasNoStoredUserpic(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $articleId = $this->insertArticle($dbLayer);
        $commentId = $this->insertComment(
            $dbLayer,
            $articleId,
            'Евгений Степанищев (bolknote.ru)',
            'author@example.test',
        );

        $I->amOnPage('https://localhost/thread-test');
        $selector = '[data-comment-id="' . $commentId . '"]';
        $I->seeElement($selector . '.has-userpic');
        $I->see('ЕС', $selector . ' .comment-userpic-fallback');
        $I->seeElement($selector . ' .comment-userpic-fallback[class*="comment-userpic-color-"]');
        $I->dontSeeElement($selector . ' .comment-userpic img');
    }

    public function testRejectsAReplyToAnUnavailableComment(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $countBefore = (int)$dbLayer->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->execute()->result();

        $I->sendPost('https://localhost/', [
            'name'      => 'Reply author',
            'email'     => 'reply@example.test',
            'text'      => 'Must not be saved',
            'parent_id' => '999999',
        ]);

        $I->seeResponseCodeIs(200);
        $I->see('The comment you replied to is no longer available');
        $I->assertSame($countBefore, (int)$dbLayer->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->execute()->result());
    }

    public function testModeratorCanEditAndHideSpamWithoutBreakingTheThread(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer  = $I->grabService(DbLayer::class);
        $articleId = $this->insertArticle($dbLayer);
        $parentId  = $this->insertComment($dbLayer, $articleId, 'Suspicious author', 'spam@example.test', text: 'Original suspicious text');
        $childId   = $this->insertComment($dbLayer, $articleId, 'Reader', 'reader@example.test', parentId: $parentId, text: 'Visible answer');

        $I->amOnPage('https://localhost/thread-test');
        $I->dontSeeElement('[data-comment-id="' . $parentId . '"] > .comment-moderation');

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/thread-test');
        $I->seeElement('[data-comment-id="' . $parentId . '"] > .comment-moderation');

        $editToken = (string)$I->grabAttributeFrom(
            '[data-comment-id="' . $parentId . '"] > .comment-edit-form input[name="moderation_token"]',
            'value',
        );
        $I->sendPost('https://localhost/comment-moderate', [
            'moderation_action' => 'edit',
            'target_type'       => ContentType::PAGE->value,
            'comment_id'        => (string)$parentId,
            'comment_anchor'    => '1',
            'moderation_token'  => $editToken,
            'return_to'         => '/thread-test',
            'text'              => 'Edited suspicious text',
        ]);
        $I->seeResponseCodeIs(303);

        $I->amOnPage('https://localhost/thread-test');
        $I->see('Edited suspicious text');

        $spamToken = (string)$I->grabAttributeFrom(
            '[data-comment-id="' . $parentId . '"] > .comment-moderation [data-moderation-action="spam"] input[name="moderation_token"]',
            'value',
        );
        $I->sendPost('https://localhost/comment-moderate', [
            'moderation_action' => 'spam',
            'target_type'       => ContentType::PAGE->value,
            'comment_id'        => (string)$parentId,
            'comment_anchor'    => '1',
            'moderation_token'  => $spamToken,
            'return_to'         => '/thread-test',
        ]);
        $I->seeResponseCodeIs(303);

        $I->amOnPage('https://localhost/thread-test');
        $I->seeElement('[data-comment-id="' . $parentId . '"].is-spam');
        $I->see('Edited suspicious text');
        $I->seeElement('[data-comment-id="' . $parentId . '"] > .comment-moderation [data-moderation-action="ham"]');
        $I->dontSeeElement('[data-comment-id="' . $parentId . '"] > .comment-moderation [data-moderation-action="spam"]');

        $hamToken = (string)$I->grabAttributeFrom(
            '[data-comment-id="' . $parentId . '"] > .comment-moderation [data-moderation-action="ham"] input[name="moderation_token"]',
            'value',
        );
        $I->sendPost('https://localhost/comment-moderate', [
            'moderation_action' => 'ham',
            'target_type'       => ContentType::PAGE->value,
            'comment_id'        => (string)$parentId,
            'comment_anchor'    => '1',
            'moderation_token'  => $hamToken,
            'return_to'         => '/thread-test',
        ]);
        $I->seeResponseCodeIs(303);

        $I->amOnPage('https://localhost/thread-test');
        $I->dontSeeElement('[data-comment-id="' . $parentId . '"].is-spam');
        $I->dontSeeElement('[data-comment-id="' . $parentId . '"] > .comment-moderation [data-moderation-action="ham"]');
        $I->seeElement('[data-comment-id="' . $parentId . '"] > .comment-moderation [data-moderation-action="spam"]');
        $I->see('Edited suspicious text');

        $spamToken = (string)$I->grabAttributeFrom(
            '[data-comment-id="' . $parentId . '"] > .comment-moderation [data-moderation-action="spam"] input[name="moderation_token"]',
            'value',
        );
        $I->sendPost('https://localhost/comment-moderate', [
            'moderation_action' => 'spam',
            'target_type'       => ContentType::PAGE->value,
            'comment_id'        => (string)$parentId,
            'comment_anchor'    => '1',
            'moderation_token'  => $spamToken,
            'return_to'         => '/thread-test',
        ]);
        $I->seeResponseCodeIs(303);

        $I->logout();
        $I->amOnPage('https://localhost/thread-test');
        $I->dontSee('Edited suspicious text');
        $I->dontSee('Suspicious author');
        $I->seeElement('[data-comment-id="' . $parentId . '"].is-deleted > .comment-tombstone');
        $I->seeElement('[data-comment-id="' . $parentId . '"] .comment-children [data-comment-id="' . $childId . '"]');
        $I->see('Visible answer');
    }

    public function testDeletedCommentBecomesATombstoneAndKeepsItsReplies(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer  = $I->grabService(DbLayer::class);
        $articleId = $this->insertArticle($dbLayer);
        $parentId  = $this->insertComment($dbLayer, $articleId, 'Former author', 'former@example.test', text: 'Text that will be deleted');
        $childId   = $this->insertComment($dbLayer, $articleId, 'Reply author', 'reply@example.test', parentId: $parentId, text: 'Reply that stays');

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/thread-test');

        $deleteToken = (string)$I->grabAttributeFrom(
            '[data-comment-id="' . $parentId . '"] > .comment-moderation [data-moderation-action="delete"] input[name="moderation_token"]',
            'value',
        );
        $I->sendPost('https://localhost/comment-moderate', [
            'moderation_action' => 'delete',
            'target_type'       => ContentType::PAGE->value,
            'comment_id'        => (string)$parentId,
            'comment_anchor'    => '1',
            'moderation_token'  => $deleteToken,
            'return_to'         => '/thread-test',
        ]);
        $I->seeResponseCodeIs(303);

        $I->sendPost('https://localhost/comment-moderate', [
            'moderation_action' => 'edit',
            'target_type'       => ContentType::PAGE->value,
            'comment_id'        => (string)$parentId,
            'comment_anchor'    => '1',
            'moderation_token'  => $deleteToken,
            'return_to'         => '/thread-test',
            'text'              => 'Attempted resurrection',
        ]);
        $I->seeResponseCodeIs(404);

        $I->logout();
        $I->amOnPage('https://localhost/thread-test');
        $I->seeElement('[data-comment-id="' . $parentId . '"].is-deleted > .comment-tombstone');
        $I->see('Comment deleted');
        $I->dontSee('Text that will be deleted');
        $I->dontSee('Former author');
        $I->seeElement('[data-comment-id="' . $parentId . '"] .comment-children [data-comment-id="' . $childId . '"]');
        $I->see('Reply that stays');
    }

    public function testModerationToolsFollowSeparateUserPermissions(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer   = $I->grabService(DbLayer::class);
        $articleId = $this->insertArticle($dbLayer);
        $commentId = $this->insertComment($dbLayer, $articleId, 'Author', 'author@example.test');
        $selector  = '[data-comment-id="' . $commentId . '"] > .comment-moderation';

        $I->login('power_moderator', 'power_moderator');
        $I->amOnPage('https://localhost/thread-test');
        $I->seeElement($selector . ' .comment-edit-start');
        $I->dontSeeElement($selector . ' [data-moderation-action="delete"]');
        $I->dontSeeElement($selector . ' [data-moderation-action="spam"]');

        $I->logout();
        $I->login('moderator', 'moderator');
        $I->amOnPage('https://localhost/thread-test');
        $I->dontSeeElement($selector . ' .comment-edit-start');
        $I->seeElement($selector . ' [data-moderation-action="delete"]');
        $I->seeElement($selector . ' [data-moderation-action="spam"]');
        $I->dontSeeElement($selector . ' [data-moderation-action="ham"]');
    }

    private function insertArticle(DbLayer $dbLayer): int
    {
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->setValue('parent_id', '1')
            ->setValue('slug_scope', "'root'")
            ->setValue('title', "'Thread test'")
            ->setValue('excerpt', "''")
            ->setValue('body', "'Page text'")
            ->setValue('created_at', ':time')->setParameter('time', time())
            ->setValue('published_at', ':time')
            ->setValue('updated_at', ':time')
            ->setValue('revision', '1')
            ->setValue('sort_order', '0')
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('slug', "'thread-test'")
            ->setValue('template', "'site.php'")
            ->execute()
        ;

        return (int)$dbLayer->insertId();
    }

    private function insertComment(
        DbLayer $dbLayer,
        int     $articleId,
        string  $nick,
        string  $email,
        ?int    $userpicId = null,
        ?int    $parentId = null,
        string  $text = 'Parent text',
    ): int
    {
        $dbLayer
            ->insert(CommentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->setValue('content_id', ':content_id')->setParameter('content_id', $articleId)
            ->setValue('parent_id', ':parent_id')->setParameter('parent_id', $parentId)
            ->setValue('userpic_id', ':userpic_id')->setParameter('userpic_id', $userpicId)
            ->setValue('time', ':time')->setParameter('time', time())
            ->setValue('ip', "'127.0.0.1'")
            ->setValue('nick', ':nick')->setParameter('nick', $nick)
            ->setValue('email', ':email')->setParameter('email', $email)
            ->setValue('subscribed', '0')
            ->setValue('shown', '1')
            ->setValue('sent', '1')
            ->setValue('good', '0')
            ->setValue('text', ':text')->setParameter('text', $text)
            ->execute()
        ;

        return (int)$dbLayer->insertId();
    }
}
