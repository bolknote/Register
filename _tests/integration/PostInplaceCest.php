<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentRepository;
use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Live\LiveUpdateRepository;
use Register\Module\LinkHealth\Manifest;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Response;

final class PostInplaceCest
{
    public function showsToolsOnlyForAnAuthorizedPostEditor(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer  = $I->grabService(DbLayer::class);
        $authorId = $this->userId($dbLayer, 'author');
        $adminId  = $this->userId($dbLayer, 'admin');
        $ownId    = $this->insertPost($dbLayer, 'author-post', $authorId);
        $otherId  = $this->insertPost($dbLayer, 'admin-post', $adminId);

        $I->amOnPage('https://localhost/author-post');
        $I->dontSeeElement('.post-card[data-post-id="' . $ownId . '"] .post-inplace-tools');
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $ownId, [
            'inplace_action' => 'edit',
            'inplace_token'  => str_repeat('0', 64),
            'revision'       => '1',
            'title'          => 'Forbidden edit',
            'body'           => '<p>Forbidden</p>',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_FORBIDDEN);

        $I->login('author', 'author');
        $I->amOnPage('https://localhost/author-post');
        $I->seeElement('.post-card[data-post-id="' . $ownId . '"] > .post-inplace-tools');
        $I->seeElement('.post-inplace-edit-form[hidden]');
        $I->seeElement('.post-delete-confirmation[hidden]');
        $I->seeElement('script[src$="/_assets/register/post-inplace.js"]');

        $I->amOnPage('https://localhost/admin-post');
        $I->dontSeeElement('.post-card[data-post-id="' . $otherId . '"] .post-inplace-tools');
    }

    public function editsAPostAndRejectsAStaleRevision(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var LiveUpdateRepository $updates */
        $updates = $I->grabService(LiveUpdateRepository::class);
        $postId  = $this->insertPost($dbLayer, 'editable-post', $this->userId($dbLayer, 'admin'));

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/editable-post');
        $selector = '.post-card[data-post-id="' . $postId . '"] > .post-inplace-edit-form';
        $token    = (string)$I->grabAttributeFrom($selector . ' input[name="inplace_token"]', 'value');
        $cursor   = $updates->currentCursor();

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [
            'inplace_action' => 'edit',
            'inplace_token'  => $token,
            'revision'       => '1',
            'return_to'      => '/editable-post',
            'title'          => 'Edited in place',
            'body'           => '<p>Updated <strong>without a reload</strong>.</p>',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsArray($payload);
        $I->assertTrue($payload['success']);
        $I->assertSame('edit', $payload['action']);
        $I->assertSame(2, $payload['revision']);
        $I->assertStringContainsString('data-post-inplace-body', $payload['body_html']);
        $I->assertStringContainsString('<strong>without a reload</strong>', $payload['body_html']);

        $stored = $dbLayer
            ->select('title, body, revision')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($stored);
        $I->assertSame('Edited in place', $stored['title']);
        $I->assertSame('<p>Updated <strong>without a reload</strong>.</p>', $stored['body']);
        $I->assertSame(2, (int)$stored['revision']);
        $I->assertGreaterThan($cursor, $updates->currentCursor());

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [
            'inplace_action' => 'edit',
            'inplace_token'  => $token,
            'revision'       => '1',
            'title'          => 'Overwrite from stale tab',
            'body'           => '<p>Stale text</p>',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_CONFLICT);
        $I->assertSame('Edited in place', $dbLayer
            ->select('title')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()
            ->result());
    }

    public function deletesAPostTogetherWithCommentsAndTagRelations(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var CommentRepository $comments */
        $comments = $I->grabService(CommentRepository::class);
        /** @var TagRepository $tags */
        $tags = $I->grabService(TagRepository::class);
        /** @var LiveUpdateRepository $updates */
        $updates = $I->grabService(LiveUpdateRepository::class);

        $postId    = $this->insertPost($dbLayer, 'deletable-post', $this->userId($dbLayer, 'admin'));
        $contentId = ContentId::post($postId);
        $commentId = $comments->save(
            $contentId,
            'Reader',
            'reader@example.test',
            false,
            false,
            'This comment is deleted with the post.',
            '127.0.0.1',
            null,
        );
        $tagId = $this->insertTag($dbLayer);
        $tags->replace($contentId, [$tagId]);
        $I->setConfigValue(
            Manifest::INVENTORY_GENERATION_CONFIG_KEY,
            (string)Manifest::INVENTORY_GENERATION,
        );

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/deletable-post');
        $selector = '.post-card[data-post-id="' . $postId . '"] > .post-delete-confirmation';
        $token    = (string)$I->grabAttributeFrom($selector . ' input[name="inplace_token"]', 'value');
        $revision = (string)$I->grabAttributeFrom($selector . ' input[name="revision"]', 'value');
        $cursor   = $updates->currentCursor();

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [
            'inplace_action' => 'delete',
            'inplace_token'  => $token,
            'revision'       => $revision,
            'return_to'      => '/deletable-post',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsArray($payload);
        $I->assertTrue($payload['success']);
        $I->assertSame('delete', $payload['action']);

        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()
            ->result());
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $commentId)
            ->execute()
            ->result());
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(ContentTagSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $postId)
            ->execute()
            ->result());
        $I->assertGreaterThan($cursor, $updates->currentCursor());
    }

    private function insertPost(DbLayer $dbLayer, string $slug, int $authorId): int
    {
        $timestamp = time();
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->values([
                'content_type'    => ':content_type',
                'slug_scope'      => "'root'",
                'created_at'      => ':time',
                'published_at'    => ':time',
                'updated_at'      => ':time',
                'revision'        => '1',
                'title'           => ':title',
                'excerpt'         => "''",
                'body'            => "'<p>Original body</p>'",
                'published'       => '1',
                'featured'        => '0',
                'comments_enabled' => '1',
                'series'          => "''",
                'slug'            => ':slug',
                'author_id'       => ':author_id',
            ])
            ->execute([
                'content_type' => ContentType::POST->value,
                'time'         => $timestamp,
                'title'        => ucfirst(str_replace('-', ' ', $slug)),
                'slug'         => $slug,
                'author_id'    => $authorId,
            ])
        ;

        return (int)$dbLayer->insertId();
    }

    private function insertTag(DbLayer $dbLayer): int
    {
        $dbLayer->insert('tags')->values([
            'name'        => "'Inplace tag'",
            'description' => "''",
            'modify_time' => ':modify_time',
            'url'         => "'inplace-tag'",
        ])->execute(['modify_time' => time()]);

        return (int)$dbLayer->insertId();
    }

    private function userId(DbLayer $dbLayer, string $login): int
    {
        return (int)$dbLayer
            ->select('id')
            ->from('users')
            ->where('login = :login')->setParameter('login', $login)
            ->execute()
            ->result()
        ;
    }
}
