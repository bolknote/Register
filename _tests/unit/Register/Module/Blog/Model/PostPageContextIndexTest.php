<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Blog\Model;

use PHPUnit\Framework\TestCase;
use Register\Module\Blog\Model\PostPageContextIndex;

final class PostPageContextIndexTest extends TestCase
{
    public function testReturnsOnlyTheRequestedPostsCrossContentContext(): void
    {
        $posts = [
            1 => $this->post('First', '/first', 'Ann', 'Series', 1, 'first'),
            2 => $this->post('Second', '/second', 'Bob', 'Series', 2, 'second'),
            3 => $this->post('Third', '/third', '', '', 3, 'third'),
        ];
        $index = new PostPageContextIndex(
            $posts,
            ['Series' => [2, 1]],
            [2 => 1, 3 => 2],
            [1 => 2, 2 => 3],
            ['2026-9' => [8 => [1, 2], 9 => [3]]],
            true,
        );

        $context = $index->forPost(2);
        self::assertNotNull($context);
        self::assertSame('Bob', $context->author);
        self::assertSame([['title' => 'First', 'link' => '/first']], $context->seeAlso);
        self::assertSame(['title' => 'First', 'link' => '/first'], $context->back);
        self::assertSame(['title' => 'Third', 'link' => '/third'], $context->forward);
        self::assertSame([8 => ['first', 'second'], 9 => ['third']], $context->dayUrls);
        self::assertNull($index->forPost(99));
    }

    /** @return array{string, string, string, string, int, string} */
    private function post(
        string $title,
        string $link,
        string $author,
        string $series,
        int $day,
        string $slug,
    ): array {
        $publishedAt = (new \DateTimeImmutable(\sprintf('2026-09-%02d 12:00:00', $day)))->getTimestamp();

        return [$title, $link, $author, $series, $publishedAt, $slug];
    }
}
