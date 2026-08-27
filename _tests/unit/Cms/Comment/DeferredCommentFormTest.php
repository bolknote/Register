<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Comment;

use PHPUnit\Framework\TestCase;
use Register\Core\Comment\DeferredCommentForm;

final class DeferredCommentFormTest extends TestCase
{
    public function testRoundTripsAnOpaqueContentIdentifier(): void
    {
        $placeholder = DeferredCommentForm::placeholder('article/ключ:42');

        self::assertTrue(DeferredCommentForm::existsIn($placeholder));
        self::assertSame(
            '<form data-content="article/ключ:42"></form>',
            DeferredCommentForm::replace(
                $placeholder,
                static fn(string $contentId): string => '<form data-content="' . $contentId . '"></form>',
            ),
        );
    }

    public function testUnrelatedContentIsNotChanged(): void
    {
        self::assertNull(DeferredCommentForm::replace(
            'ordinary response',
            static fn(string $contentId): string => $contentId,
        ));
    }

    public function testInvalidContentIdentifierIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeferredCommentForm::placeholder('');
    }
}
