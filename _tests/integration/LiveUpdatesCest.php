<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentRepository;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Live\LiveUpdateRepository;
use Register\Core\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Response;

final class LiveUpdatesCest
{
    public function exposesLiveConfigurationOnlyWithSubscribedRegions(\IntegrationTester $I): void
    {
        $I->amOnPage('https://localhost/');

        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->seeElement('[data-live-region="posts:0"]');
        $I->seeElement('meta[name="register-live-updates"][data-endpoint="/_live"]');
        $I->seeElement('meta[name="register-offline"][data-worker="/service-worker.js"][data-scope="/"][data-seed="1"]');

        $html = $I->grabResponse();
        $I->assertStringContainsString('/_assets/register/offline.js', $html);
        $I->assertStringContainsString('/_assets/register/offline.css', $html);
    }

    public function keepsSensitiveServicePagesOutOfTheOfflineCache(\IntegrationTester $I): void
    {
        foreach (['/comment_unsubscribe', '/comment_sent'] as $path) {
            $I->amOnPage('https://localhost' . $path);

            $I->seeResponseCodeIs(Response::HTTP_OK);
            $I->seeElement('meta[name="register-offline"][data-seed="0"]');
            $I->assertStringContainsString('no-store', (string)$I->grabHttpHeader('Cache-Control'));
            $I->assertNull($I->grabHttpHeader('X-Register-Offline-Cache'));
        }
    }

    public function returnsPostAndCommentChangesInOneRequest(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var LiveUpdateRepository $updates */
        $updates = $I->grabService(LiveUpdateRepository::class);
        /** @var CommentRepository $comments */
        $comments = $I->grabService(CommentRepository::class);

        $postId    = $this->insertPost($dbLayer);
        $contentId = ContentId::post($postId);
        $updates->publishContent($contentId);

        $I->amOnPage('https://localhost/live-post');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->seeElement('[data-live-region="comments:post:' . $postId . '"]');
        $I->seeElement('meta[name="register-live-updates"]');

        $cursor = $updates->currentCursor();
        $commentId = $comments->save(
            $contentId,
            'Live reader',
            'live@example.test',
            false,
            'Comment delivered without a reload',
            '127.0.0.1',
            null,
        );
        $comments->publish($commentId, ContentType::POST);

        $query = http_build_query([
            'cursor' => $cursor,
            'region' => ['posts:0', 'comments:post:' . $postId],
        ]);
        $I->sendRequestWithMethod('GET', 'https://localhost/_live?' . $query);

        $I->seeResponseCodeIs(Response::HTTP_OK);

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsArray($payload);
        $I->assertGreaterThan($cursor, $payload['cursor']);
        $I->assertFalse($payload['more']);
        $I->assertArrayHasKey('posts:0', $payload['patches']);
        $I->assertArrayHasKey('comments:post:' . $postId, $payload['patches']);
        $I->assertStringContainsString('data-live-region="posts:0"', $payload['patches']['posts:0']);
        $I->assertStringContainsString(
            'Comment delivered without a reload',
            $payload['patches']['comments:post:' . $postId],
        );
    }

    public function updatesThePendingCommentSignalForAModerator(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var LiveUpdateRepository $updates */
        $updates = $I->grabService(LiveUpdateRepository::class);
        /** @var CommentRepository $comments */
        $comments = $I->grabService(CommentRepository::class);

        $postId      = $this->insertPost($dbLayer);
        $contentId   = ContentId::post($postId);
        $pendingBefore = $comments->countPending();

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');
        $I->seeElement('.site-header-tools .post-create-start[data-editor-shortcut="create"]');
        $I->seeElement('.site-header-tools[data-live-region="site-tools"]');
        if ($pendingBefore === 0) {
            $I->dontSeeElement('.site-header-new-comments');
        } else {
            $I->seeElement('.site-header-new-comments[data-pending-comments-count="' . $pendingBefore . '"]');
        }

        $cursor = $updates->currentCursor();
        $commentId = $comments->save(
            $contentId,
            'New reader',
            'new-reader@example.test',
            false,
            'Awaiting moderation',
            '127.0.0.1',
            null,
        );

        $query = http_build_query([
            'cursor' => $cursor,
            'region' => ['site-tools'],
        ]);
        $I->sendRequestWithMethod('GET', 'https://localhost/_live?' . $query);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertArrayHasKey('site-tools', $payload['patches']);
        $I->assertStringContainsString('data-live-region="site-tools"', $payload['patches']['site-tools']);
        $I->assertStringContainsString(
            'data-pending-comments-count="' . ($pendingBefore + 1) . '"',
            $payload['patches']['site-tools'],
        );
        $I->assertStringContainsString('entity=Comment', $payload['patches']['site-tools']);

        $cursor = (int)$payload['cursor'];
        $comments->publish($commentId, ContentType::POST);
        $query = http_build_query([
            'cursor' => $cursor,
            'region' => ['site-tools'],
        ]);
        $I->sendRequestWithMethod('GET', 'https://localhost/_live?' . $query);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertArrayHasKey('site-tools', $payload['patches']);
        if ($pendingBefore === 0) {
            $I->assertStringNotContainsString('site-header-new-comments', $payload['patches']['site-tools']);
        } else {
            $I->assertStringContainsString(
                'data-pending-comments-count="' . $pendingBefore . '"',
                $payload['patches']['site-tools'],
            );
        }
    }

    public function rejectsMalformedSubscriptions(\IntegrationTester $I): void
    {
        $I->sendRequestWithMethod('GET', 'https://localhost/_live?cursor=-1&region[]=posts:0');
        $I->seeResponseCodeIs(Response::HTTP_BAD_REQUEST);

        $I->sendRequestWithMethod('GET', 'https://localhost/_live?cursor=0&region[]=unknown');
        $I->seeResponseCodeIs(Response::HTTP_BAD_REQUEST);
    }

    private function insertPost(DbLayer $dbLayer): int
    {
        $timestamp = 1_700_000_100;
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::POST->value)
            ->setValue('slug_scope', "'root'")
            ->setValue('created_at', ':time')->setParameter('time', $timestamp)
            ->setValue('published_at', ':time')
            ->setValue('updated_at', ':time')
            ->setValue('revision', '1')
            ->setValue('title', "'Live post'")
            ->setValue('excerpt', "''")
            ->setValue('body', "'<p>Live body</p>'")
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('series', "''")
            ->setValue('slug', "'live-post'")
            ->setValue('author_id', 'NULL')
            ->execute()
        ;

        return (int)$dbLayer->insertId();
    }
}
