<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Author\AuthorProfileRepository;

/** Composes public content capabilities for optional integrations. */
final readonly class ContentDetailsRepository
{
    public function __construct(
        private ContentRepository       $contentRepository,
        private AuthorProfileRepository $authorProfileRepository,
        private TagRepository           $tagRepository,
    ) {
    }

    public function find(ContentId $contentId): ?ContentDetails
    {
        $content = $this->contentRepository->find($contentId);
        if (!$content instanceof ContentItem) {
            return null;
        }

        $author = $content->authorId === null
            ? null
            : $this->authorProfileRepository->find($content->authorId);
        $tags = $this->tagRepository->findForContent([$contentId])[(string)$contentId];

        return new ContentDetails($content, $author, $tags);
    }
}
