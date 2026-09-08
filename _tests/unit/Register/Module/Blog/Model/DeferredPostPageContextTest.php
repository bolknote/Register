<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Blog\Model;

use PHPUnit\Framework\TestCase;
use Register\Module\Blog\Model\DeferredPostPageContext;

final class DeferredPostPageContextTest extends TestCase
{
    public function testReplacesEveryParameterizedSlot(): void
    {
        $content = implode('|', [
            DeferredPostPageContext::placeholder(DeferredPostPageContext::AUTHOR, 7),
            DeferredPostPageContext::placeholder(DeferredPostPageContext::SEE_ALSO, 7),
            DeferredPostPageContext::placeholder(DeferredPostPageContext::BACK_FORWARD, 8),
            DeferredPostPageContext::placeholder(DeferredPostPageContext::HEAD_LINKS, 8),
            DeferredPostPageContext::placeholder(DeferredPostPageContext::CALENDAR, 8),
        ]);

        self::assertTrue(DeferredPostPageContext::existsIn($content));
        self::assertSame(
            'author:7|see-also:7|back-forward:8|head-links:8|calendar:8',
            DeferredPostPageContext::replace(
                $content,
                static fn(string $slot, int $postId): string => $slot . ':' . $postId,
            ),
        );
    }

    public function testUnrelatedContentIsNotChanged(): void
    {
        self::assertNull(DeferredPostPageContext::replace(
            'ordinary response',
            static fn(string $slot, int $postId): string => $slot . $postId,
        ));
    }

    public function testInvalidSlotAndPostIdAreRejected(): void
    {
        try {
            DeferredPostPageContext::placeholder('unknown', 1);
            self::fail('An unknown slot must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        DeferredPostPageContext::placeholder(DeferredPostPageContext::AUTHOR, 0);
    }
}
