<?php

declare(strict_types = 1);

namespace integration;

use Register\Auth\PublicAuthSettings;
use Register\Comment\CommentAgePolicy;
use Register\Comment\CommentRepository;
use Register\Comment\CommentSchema;
use Register\Comment\ContentCommentTargetResolver;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;

final class CommentAgeCest
{
    public function oldDiscussionsStayVisibleButRejectNewCommentsAndPreview(\IntegrationTester $I): void
    {
        $I->setConfigValue(CommentAgePolicy::CONFIG_KEY, '14');
        $postId = $this->insertContent($I, 'old-discussion', time() - 15 * 86400);
        /** @var CommentRepository $comments */
        $comments = $I->grabService(CommentRepository::class);
        $commentId = $comments->save(ContentId::post($postId), 'Reader', 'reader@example.test', false, 'Existing discussion text', '', null);
        $comments->publish($commentId, ContentType::POST);

        $I->amOnPage('/old-discussion');
        $I->see('Existing discussion text');
        $I->see('The discussion is closed');
        $I->dontSeeElement('#comment-form');
        $I->dontSeeElement('.comment-reply');

        $I->login('admin', 'admin');
        foreach ([[], ['preview' => '1']] as $extra) {
            $I->sendPost('/old-discussion', ['text' => 'Rejected new text', ...$extra]);
            $I->seeResponseCodeIs(403);
            $I->see('The discussion is closed');
        }

        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $I->assertSame(1, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->where('content_id = :id')->setParameter('id', $postId)->execute()->result());
    }

    public function disablingTheLimitReopensOldPostsAndPermanentPagesAreUnaffected(\IntegrationTester $I): void
    {
        $this->insertContent($I, 'age-toggle', 1);
        $this->insertContent($I, 'evergreen-page', 1, ContentType::PAGE);
        $I->setConfigValue(CommentAgePolicy::CONFIG_KEY, '14');
        $I->amOnPage('/age-toggle');
        $I->dontSeeElement('#comment-form');
        $I->amOnPage('/evergreen-page');
        $I->seeElement('#comment-form');
        $I->setConfigValue(CommentAgePolicy::CONFIG_KEY, '0');
        $I->amOnPage('/age-toggle');
        $I->seeElement('#comment-form');
    }

    public function ageIsBasedOnPublicationNotCreationAndAppliesToEmailConfirmation(\IntegrationTester $I): void
    {
        $I->setConfigValue(CommentAgePolicy::CONFIG_KEY, '14');
        $I->setConfigValue(PublicAuthSettings::EMAIL_ENABLED_CONFIG_KEY, '1');

        $postId = $this->insertContent($I, 'recent-discussion', time() - 60);
        $I->amOnPage('/recent-discussion');
        $I->seeElement('#comment-form');
        $I->sendPost('/recent-discussion', [
            'name' => 'Reader', 'email' => 'reader@example.test', 'text' => 'Waiting for verification',
        ]);
        $I->seeResponseCodeIs(302);

        $mails = $I->grabPublicAuthMails();
        $I->assertCount(1, $mails);
        preg_match('~https?://[^\\s]+/auth/email/callback\\?token=[A-Za-z0-9_-]+~', $mails[0]['message'], $matches);
        $I->assertNotEmpty($matches);
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $db->update(ContentSchema::TABLE_NAME)->set('published_at', ':time')->setParameter('time', time() - 15 * 86400)->where('id = :id')->setParameter('id', $postId)->execute();
        $I->amOnPage($matches[0]);
        $I->assertSame(0, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)->where('content_id = :id')->setParameter('id', $postId)->execute()->result());
    }

    public function unpublishedAndFuturePostsCannotReceiveDirectComments(\IntegrationTester $I): void
    {
        $postId = $this->insertContent($I, 'future-discussion', time() + 86400);
        /** @var ContentCommentTargetResolver $resolver */
        $resolver = $I->grabService(ContentCommentTargetResolver::class);
        $request = Request::create('/future-discussion');
        $request->attributes->set('url', 'future-discussion');

        $I->assertNull($resolver->fromRequest(ContentType::POST, $request));
        $target = $resolver->fromId(ContentId::post($postId));
        $I->assertNotNull($target);
        $I->assertFalse($target->commentsAllowed);
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $db->update(ContentSchema::TABLE_NAME)->set('published', '0')->set('published_at', '1')
            ->where('id = :id')->setParameter('id', $postId)->execute();
        $I->assertNull($resolver->fromRequest(ContentType::POST, $request));
        $target = $resolver->fromId(ContentId::post($postId));
        $I->assertNotNull($target);
        $I->assertFalse($target->commentsAllowed);
    }

    private function insertContent(\IntegrationTester $I, string $slug, int $publishedAt, ContentType $type = ContentType::POST): int
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $db->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':type', 'slug_scope' => "'root'", 'slug' => ':slug',
            'parent_id' => $type === ContentType::PAGE ? '1' : 'NULL',
            'title' => ':slug', 'body' => "'<p>Discussion body</p>'", 'excerpt' => "''",
            'created_at' => '1', 'published_at' => ':published', 'updated_at' => '1',
            'published' => '1', 'comments_enabled' => '1',
            'template' => "'site.php'",
        ])->execute(['type' => $type->value, 'slug' => $slug, 'published' => $publishedAt]);

        return (int)$db->insertId();
    }
}
