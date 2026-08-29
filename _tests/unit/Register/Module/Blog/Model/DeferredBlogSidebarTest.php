<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Blog\Model;

use PHPUnit\Framework\TestCase;
use Register\Module\Blog\Model\DeferredBlogSidebar;

final class DeferredBlogSidebarTest extends TestCase
{
    public function testReplacesOnlyPresentSidebarSlots(): void
    {
        $content = implode('|', [
            DeferredBlogSidebar::placeholder(DeferredBlogSidebar::RECENT_COMMENTS),
            'body',
            DeferredBlogSidebar::placeholder(DeferredBlogSidebar::RECENT_DISCUSSIONS),
        ]);

        self::assertTrue(DeferredBlogSidebar::existsIn($content));
        self::assertSame(
            'rendered:recent-comments|body|rendered:recent-discussions',
            DeferredBlogSidebar::replace(
                $content,
                static fn(string $slot): string => 'rendered:' . $slot,
            ),
        );
    }

    public function testUnrelatedContentIsNotChanged(): void
    {
        self::assertNull(DeferredBlogSidebar::replace(
            'ordinary response',
            static fn(string $slot): string => $slot,
        ));
    }

    public function testUnknownSlotIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeferredBlogSidebar::placeholder('unknown');
    }
}
