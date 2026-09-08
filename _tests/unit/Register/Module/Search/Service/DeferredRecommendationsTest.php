<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Service;

use PHPUnit\Framework\TestCase;
use Register\Content\ContentId;
use Register\Module\Search\Service\DeferredRecommendations;

final class DeferredRecommendationsTest extends TestCase
{
    public function testReplacesPostAndPageRecommendations(): void
    {
        $content = DeferredRecommendations::placeholder(ContentId::post(7))
            . '|body|'
            . DeferredRecommendations::placeholder(ContentId::page(8));

        self::assertTrue(DeferredRecommendations::existsIn($content));
        self::assertSame(
            'recommendations:post:7|body|recommendations:page:8',
            DeferredRecommendations::replace(
                $content,
                static fn(ContentId $contentId): string => 'recommendations:' . (string)$contentId,
            ),
        );
    }

    public function testUnrelatedContentIsNotChanged(): void
    {
        self::assertNull(DeferredRecommendations::replace(
            'ordinary response',
            static fn(ContentId $contentId): string => (string)$contentId,
        ));
    }
}
