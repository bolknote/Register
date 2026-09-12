<?php

declare(strict_types = 1);

namespace unit\Register\Comment;

use Codeception\Test\Unit;
use Register\Comment\Comment;
use Register\Comment\CommentDeletionState;
use Register\Content\ContentId;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Model\Comment\CommentModerationTokenManager;

final class CommentUndoTokenTest extends Unit
{
    public function testUndoIsBoundToSessionCommentRevisionAndExpiry(): void
    {
        $manager = new CommentModerationTokenManager(new SpamIdentityHasher(str_repeat('s', 32)));
        $state = new CommentDeletionState(200, true, false, true);
        $token = $manager->issueUndo('session-a', $this->comment(), $state, 1000);
        $restored = $manager->readUndo($token, 'session-a', $this->comment(), 1100);
        self::assertEquals($state, $restored);
        self::assertNull($manager->readUndo($token, 'session-b', $this->comment(), 1100));
        self::assertNull($manager->readUndo($token, 'session-a', $this->comment(id: 11), 1100));
        self::assertNull($manager->readUndo($token, 'session-a', $this->comment(revision: 201), 1100));
        self::assertNull($manager->readUndo($token, 'session-a', $this->comment(deleted: false), 1100));
        self::assertNull($manager->readUndo($token, 'session-a', $this->comment(text: 'Changed privately'), 1100));
        self::assertNull($manager->readUndo($token, 'session-a', $this->comment(), 1601));
        self::assertNull($manager->readUndo(str_replace('.1.0.1.', '.0.0.1.', $token), 'session-a', $this->comment(), 1100));
        self::assertStringNotContainsString('Private text', $token);
        self::assertStringNotContainsString('author@example.test', $token);
    }

    private function comment(int $id = 10, int $revision = 200, bool $deleted = true, string $text = 'Private text'): Comment
    {
        return new Comment(
            id: $id, contentId: ContentId::page(2), parentId: null, userId: null,
            visitorId: null, userpicId: null, time: 100, modifyTime: $revision,
            ip: '192.0.2.1', name: 'Author', email: 'author@example.test',
            subscribed: false, shown: false, deleted: $deleted, sent: true, good: false, text: $text,
        );
    }
}
