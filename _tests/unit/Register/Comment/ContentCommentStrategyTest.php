<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Comment;

use Codeception\Test\Unit;
use Register\Content\ContentType;
use Register\Core\Controller\CommentController;

final class ContentCommentStrategyTest extends Unit
{
    public function testPendingCommentSignaturesAreIsolatedByContentType(): void
    {
        $pageHash = CommentController::commentHash(7, 11, 'reader@example.test', '127.0.0.1', ContentType::PAGE);
        $postHash = CommentController::commentHash(7, 11, 'reader@example.test', '127.0.0.1', ContentType::POST);

        self::assertNotSame($pageHash, $postHash);
        self::assertSame($pageHash, CommentController::commentHash(
            7,
            11,
            'reader@example.test',
            '127.0.0.1',
            ContentType::PAGE,
        ));
    }
}
