<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Service;

use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Search\Service\SimilarWordsDetector;

readonly class TagsSearchProvider
{
    public function __construct(
        private TagRepository        $tagRepository,
        private SimilarWordsDetector $similarWordsDetector,
        private BlogUrlBuilder       $blogUrlBuilder,
    ) {
    }

    /**
     * @param string[] $words
     * @return string[]
     */
    public function findBlogTags(array $words): array
    {
        $tags = [];
        foreach ($this->tagRepository->findPublishedUsage(ContentType::POST) as $usage) {
            $tag = $usage->tag;
            if ($this->similarWordsDetector->wordIsSimilarToOtherWords($tag->name, $words)) {
                $tags[] = '<a href="' . $this->blogUrlBuilder->tag($tag->slug) . '">' . s2_htmlencode($tag->name) . '</a>';
            }
        }

        return $tags;
    }
}
