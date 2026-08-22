<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentRepository;
use Register\Comment\CommentChangeKind;
use Register\Comment\CommentChangedEvent;
use Register\Comment\CommentImport;
use Register\Comment\CommentImportService;
use Register\Comment\CommentMutationSource;
use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class CommentRepositoryCest
{
    public function storesOneGloballyIdentifiedThreadForEveryContentType(\IntegrationTester $I): void
    {
        $events = [];
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $I->grabService(EventDispatcherInterface::class);
        $eventDispatcher->addListener(
            CommentChangedEvent::class,
            static function (CommentChangedEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var CommentRepository $repository */
        $repository = $I->grabService(CommentRepository::class);
        $userId = (int)$dbLayer
            ->select('id')
            ->from('users')
            ->where("login = 'admin'")
            ->execute()
            ->result()
        ;

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
            $userId,
        );
        $repository->publish($pageCommentId, ContentType::PAGE);
        $replyId = $repository->save(
            $page,
            'Reply author',
            'reply@example.com',
            false,
            false,
            'Reply',
            '127.0.0.2',
            $pageCommentId,
            $userId,
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
            $userId,
        );

        $I->assertNotSame($pageCommentId, $postCommentId);
        $I->assertSame([$pageCommentId, $replyId], array_column($repository->findForContent($page), 'id'));
        $I->assertSame([$postCommentId], array_column($repository->findForContent($post), 'id'));

        $pageComment = $repository->find($pageCommentId);
        $I->assertNotNull($pageComment);
        $I->assertSame($pageCommentId, $pageComment->id);
        $I->assertSame(0, $pageComment->modifyTime);

        $editTime = time();
        $I->assertTrue($repository->edit($pageCommentId, ContentType::PAGE, 'Edited page comment'));
        $editedComment = $repository->find($pageCommentId);
        $I->assertNotNull($editedComment);
        $I->assertGreaterThanOrEqual($editTime, $editedComment->modifyTime);
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
        $I->assertTrue($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'modify_time'));

        $repository->removeForContent($page);
        $I->assertSame([], $repository->findForContent($page));
        $I->assertNotNull($repository->find($postCommentId));
        $I->assertSame([
            CommentChangeKind::CREATED,
            CommentChangeKind::PUBLISHED,
            CommentChangeKind::CREATED,
            CommentChangeKind::CREATED,
            CommentChangeKind::EDITED,
            CommentChangeKind::REMOVED,
            CommentChangeKind::REMOVED,
        ], array_column($events, 'kind'));
        $I->assertSame(CommentMutationSource::LOCAL, $events[0]->source);
    }

    public function importsWithoutInventingContactOrNetworkIdentity(\IntegrationTester $I): void
    {
        $events = [];
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $I->grabService(EventDispatcherInterface::class);
        $eventDispatcher->addListener(
            CommentChangedEvent::class,
            static function (CommentChangedEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        /** @var CommentImportService $importService */
        $importService = $I->grabService(CommentImportService::class);
        /** @var CommentRepository $repository */
        $repository = $I->grabService(CommentRepository::class);

        $commentId = $importService->import(new CommentImport(
            ContentId::page(1),
            'Remote author',
            '<p>Remote reply</p>',
            null,
            123,
        ));
        $comment = $repository->find($commentId);

        $I->assertNotNull($comment);
        $I->assertSame('', $comment->email);
        $I->assertSame('', $comment->ip);
        $I->assertFalse($comment->showEmail);
        $I->assertFalse($comment->subscribed);
        $I->assertSame(CommentMutationSource::IMPORTED, $events[0]->source);
        $I->assertSame(CommentChangeKind::CREATED, $events[0]->kind);
    }

    public function keepsDeletedCommentsOnlyWhileTheyAnchorReplies(\IntegrationTester $I): void
    {
        $events = [];
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $I->grabService(EventDispatcherInterface::class);
        $eventDispatcher->addListener(
            CommentChangedEvent::class,
            static function (CommentChangedEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        /** @var CommentRepository $repository */
        $repository = $I->grabService(CommentRepository::class);

        $contentId = ContentId::page(1);
        $rootId = $repository->save(
            $contentId,
            'Root author',
            'root@example.com',
            false,
            false,
            'Root comment',
            '127.0.0.1',
            null,
        );
        $repository->publish($rootId, ContentType::PAGE);
        $replyId = $repository->save(
            $contentId,
            'Reply author',
            'reply@example.com',
            false,
            false,
            'Reply comment',
            '127.0.0.2',
            $rootId,
        );
        $events = [];

        $I->assertTrue($repository->tombstone($rootId, ContentType::PAGE));
        $root = $repository->find($rootId);
        $I->assertNotNull($root);
        $I->assertTrue($root->deleted);
        $I->assertSame([CommentChangeKind::TOMBSTONED], array_column($events, 'kind'));

        $I->assertTrue($repository->tombstone($replyId, ContentType::PAGE));
        $I->assertNull($repository->find($replyId));
        $I->assertNull($repository->find($rootId));
        $I->assertSame(
            [CommentChangeKind::TOMBSTONED, CommentChangeKind::REMOVED, CommentChangeKind::REMOVED],
            array_column($events, 'kind'),
        );
        $I->assertSame([$rootId, $replyId, $rootId], array_column($events, 'commentId'));
    }
}
