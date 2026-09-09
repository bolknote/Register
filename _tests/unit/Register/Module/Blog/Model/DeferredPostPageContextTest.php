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
use Register\Module\Typography\Typograph;

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

    public function testAttributePlaceholderSurvivesTypographyWithoutBreakingTheOpeningTag(): void
    {
        $content = '<article data-analytics-author="'
            . DeferredPostPageContext::attributePlaceholder(DeferredPostPageContext::AUTHOR, 7)
            . '" data-analytics-section="web-dev" data-analytics-published-at="1700000001">';

        $typographed = Typograph::process($content, 'ru');

        self::assertSame($content, $typographed);
        self::assertSame(
            '<article data-analytics-author="Author" data-analytics-section="web-dev" data-analytics-published-at="1700000001">',
            DeferredPostPageContext::replace(
                $typographed,
                static fn(string $slot, int $postId): string => $slot === DeferredPostPageContext::AUTHOR && $postId === 7
                    ? 'Author'
                    : '',
            ),
        );
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
