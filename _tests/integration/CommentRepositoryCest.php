<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentRepository;
use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentType;
use S2\Cms\Pdo\DbLayer;

final class CommentRepositoryCest
{
    public function storesOneGloballyIdentifiedThreadForEveryContentType(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var CommentRepository $repository */
        $repository = $I->grabService(CommentRepository::class);

        $page = ContentId::page(1);
        $post = ContentId::post(1);

        $pageCommentId = $repository->save(
            $page,
            'Page author',
            'page@example.com',
            true,
            true,
            'Page comment',
            '127.0.0.1',
            null,
            100,
        );
        $repository->publish($pageCommentId);
        $replyId = $repository->save(
            $page,
            'Reply author',
            'reply@example.com',
            false,
            false,
            'Reply',
            '127.0.0.2',
            $pageCommentId,
            101,
        );
        $postCommentId = $repository->save(
            $post,
            'Post author',
            'post@example.com',
            false,
            false,
            'Post comment',
            '127.0.0.3',
            null,
            102,
        );

        $I->assertNotSame($pageCommentId, $postCommentId);
        $I->assertSame([$pageCommentId, $replyId], array_column($repository->findForContent($page), 'id'));
        $I->assertSame([$postCommentId], array_column($repository->findForContent($post), 'id'));
        $I->assertSame($pageCommentId, $repository->find($pageCommentId)?->id);
        $I->assertSame(1, $repository->count($page));
        $I->assertSame(2, $repository->count($page, true));
        $I->assertTrue($repository->isValidParent($page, $pageCommentId));
        $I->assertFalse($repository->isValidParent($post, $pageCommentId));
        $I->assertSame(
            [$postCommentId],
            array_column($repository->findRecentPending(ContentType::POST, '127.0.0.3', 100), 'id'),
        );

        $I->expectThrowable(
            \InvalidArgumentException::class,
            static fn(): int => $repository->save(
                $post,
                'Invalid reply',
                'invalid@example.com',
                false,
                false,
                'Wrong thread',
                '127.0.0.4',
                $pageCommentId,
            ),
        );

        $I->assertTrue($dbLayer->tableExists(CommentSchema::TABLE_NAME));
        $I->assertTrue($dbLayer->indexExists(CommentSchema::TABLE_NAME, 'content_sort_idx'));
        $I->assertTrue($dbLayer->indexExists(CommentSchema::TABLE_NAME, 'thread_idx'));
        $I->assertTrue($dbLayer->indexExists(CommentSchema::TABLE_NAME, 'moderation_idx'));

        $repository->removeForContent($page);
        $I->assertSame([], $repository->findForContent($page));
        $I->assertNotNull($repository->find($postCommentId));
    }
}
