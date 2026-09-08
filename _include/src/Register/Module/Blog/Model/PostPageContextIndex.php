<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

/** Serializable, bounded source for all cross-post fragments on cached post pages. */
final readonly class PostPageContextIndex
{
    /**
     * @param array<int, array{string, string, string, string, int, string}> $posts title, link, author, series, published timestamp, slug
     * @param array<string, list<int>> $series
     * @param array<int, int> $back
     * @param array<int, int> $forward
     * @param array<string, array<int, list<int>>> $monthPosts
     */
    public function __construct(
        private array $posts,
        private array $series,
        private array $back,
        private array $forward,
        private array $monthPosts,
        private bool  $showAuthors,
    ) {
    }

    public function forPost(int $postId): ?PostPageContext
    {
        $post = $this->posts[$postId] ?? null;
        if (!\is_array($post)) {
            return null;
        }

        $seeAlso = [];
        if ($post[3] !== '') {
            foreach ($this->series[$post[3]] ?? [] as $relatedId) {
                if ($relatedId === $postId || !isset($this->posts[$relatedId])) {
                    continue;
                }

                $seeAlso[] = $this->link($relatedId);
            }
        }

        $backId = $this->back[$postId] ?? null;
        $forwardId = $this->forward[$postId] ?? null;

        $publishedAt = $post[4];
        $dayUrls = [];
        $monthKey = date('Y-n', $publishedAt);
        foreach ($this->monthPosts[$monthKey] ?? [] as $day => $postIds) {
            foreach ($postIds as $monthPostId) {
                if (isset($this->posts[$monthPostId])) {
                    $dayUrls[$day][] = $this->posts[$monthPostId][5];
                }
            }
        }

        return new PostPageContext(
            $this->showAuthors ? $post[2] : '',
            $seeAlso,
            $backId === null ? null : $this->link($backId),
            $forwardId === null ? null : $this->link($forwardId),
            (int)date('Y', $publishedAt),
            (int)date('n', $publishedAt),
            (int)date('j', $publishedAt),
            $post[5],
            $dayUrls,
        );
    }

    /** @return array{title: string, link: string} */
    private function link(int $postId): array
    {
        return [
            'title' => $this->posts[$postId][0],
            'link'  => $this->posts[$postId][1],
        ];
    }
}
