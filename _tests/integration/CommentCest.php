<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace integration;

use S2\Cms\Pdo\DbLayer;

class CommentCest
{
    public function testPreviewAcceptsAnEmptyParentId(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertArticle($dbLayer);
        $countBefore = (int)$dbLayer->select('COUNT(*)')->from('art_comments')->execute()->result();

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
        $I->dontSeeElement('.comment-preview-item .comment-reply');
        $I->assertSame($countBefore, (int)$dbLayer->select('COUNT(*)')->from('art_comments')->execute()->result());
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
            ->select('parent_id')
            ->from('art_comments')
            ->where('text = :text')->setParameter('text', 'Top-level comment text')
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($comment);
        $I->assertNull($comment['parent_id']);
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
            ->select('id', 'parent_id')
            ->from('art_comments')
            ->where('text = :text')->setParameter('text', 'A nested reply')
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($reply);
        $I->assertSame($parentId, (int)$reply['parent_id']);

        $I->amOnPage('https://localhost/thread-test');
        $I->seeElement(
            '[data-comment-id="' . $parentId . '"] .comment-children [data-comment-id="' . (int)$reply['id'] . '"]'
        );
        $I->seeElement('[data-comment-id="' . $parentId . '"] .comment-reply');
    }

    public function testRejectsAReplyToAnUnavailableComment(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $countBefore = (int)$dbLayer->select('COUNT(*)')->from('art_comments')->execute()->result();

        $I->sendPost('https://localhost/', [
            'name'      => 'Reply author',
            'email'     => 'reply@example.test',
            'text'      => 'Must not be saved',
            'parent_id' => '999999',
        ]);

        $I->seeResponseCodeIs(200);
        $I->see('The comment you replied to is no longer available');
        $I->assertSame($countBefore, (int)$dbLayer->select('COUNT(*)')->from('art_comments')->execute()->result());
    }

    private function insertArticle(DbLayer $dbLayer): int
    {
        $dbLayer
            ->insert('articles')
            ->setValue('parent_id', '1')
            ->setValue('title', "'Thread test'")
            ->setValue('excerpt', "''")
            ->setValue('pagetext', "'Page text'")
            ->setValue('create_time', ':time')->setParameter('time', time())
            ->setValue('modify_time', ':time')
            ->setValue('revision', '1')
            ->setValue('priority', '0')
            ->setValue('published', '1')
            ->setValue('favorite', '0')
            ->setValue('commented', '1')
            ->setValue('url', "'thread-test'")
            ->setValue('template', "'site.php'")
            ->execute()
        ;

        return (int)$dbLayer->insertId();
    }

    private function insertComment(DbLayer $dbLayer, int $articleId, string $nick, string $email): int
    {
        $dbLayer
            ->insert('art_comments')
            ->setValue('article_id', ':article_id')->setParameter('article_id', $articleId)
            ->setValue('parent_id', 'NULL')
            ->setValue('time', ':time')->setParameter('time', time())
            ->setValue('ip', "'127.0.0.1'")
            ->setValue('nick', ':nick')->setParameter('nick', $nick)
            ->setValue('email', ':email')->setParameter('email', $email)
            ->setValue('show_email', '0')
            ->setValue('subscribed', '0')
            ->setValue('shown', '1')
            ->setValue('sent', '1')
            ->setValue('good', '0')
            ->setValue('text', "'Parent text'")
            ->execute()
        ;

        return (int)$dbLayer->insertId();
    }
}
