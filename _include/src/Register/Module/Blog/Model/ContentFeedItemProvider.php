<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentType;
use Register\Content\Tag;
use Register\Content\TagRepository;
use Register\Core\Controller\Rss\FeedItemDto;
use Register\Core\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\Module as BlogModule;
use Register\Url\ContentUrlGenerator;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Maps published content to the richer item contract shared by RSS and JSON Feed. */
final readonly class ContentFeedItemProvider
{
    public function __construct(
        private PostProvider         $postProvider,
        private TagRepository        $tagRepository,
        private BlogUrlBuilder       $blogUrlBuilder,
        private ContentUrlGenerator  $contentUrlGenerator,
        private TranslatorInterface  $translator,
        private Viewer               $viewer,
    ) {
    }

    /**
     * @param iterable<ContentItem> $contentItems
     * @return list<FeedItemDto>
     */
    public function provide(iterable $contentItems): array
    {
        $items = is_array($contentItems) ? array_values($contentItems) : iterator_to_array($contentItems, false);
        $contentIds = array_map(static fn(ContentItem $item): ContentId => $item->id, $items);
        $tagsByContent = $this->tagRepository->findForContent($contentIds);

        $postIds = [];
        $series = [];
        foreach ($items as $item) {
            if ($item->id->type !== ContentType::POST) {
                continue;
            }

            $postIds[] = $item->id->value;
            if ($item->series !== '') {
                $series[$item->series] = 1;
            }
        }

        $related = [];
        $ignoredTags = [];
        if ($postIds !== []) {
            $this->postProvider->postsLinks($postIds, $series, $related, $ignoredTags);
        }

        $feedItems = [];
        foreach ($items as $item) {
            $tags = $tagsByContent[(string)$item->id] ?? [];
            $tagLinks = array_map(fn(Tag $tag): array => [
                'title' => $tag->name,
                'link'  => $this->blogUrlBuilder->tag($tag->slug),
            ], $tags);

            $publishedAt = $item->publishedAt ?? 0;
            $feedItems[] = new FeedItemDto(
                title: $item->title,
                author: $item->author,
                link: $this->contentUrlGenerator->absolutePath($item->path),
                text: $this->renderBody($item, $related, $tagLinks),
                time: $publishedAt,
                modifyTime: max($item->updatedAt ?? 0, $publishedAt),
                summary: $this->summary($item),
                image: $this->absoluteImage($item->socialImage !== ''
                    ? $item->socialImage
                    : $this->firstImage($item->body)),
                tags: array_map(static fn(Tag $tag): string => $tag->name, $tags),
            );
        }

        return $feedItems;
    }

    /**
     * @param array<mixed>                                  $related
     * @param list<array{title: string, link: string}> $tags
     */
    private function renderBody(ContentItem $item, array $related, array $tags): string
    {
        $postRelated = $item->id->type === ContentType::POST && $item->series !== ''
            ? ($related[$item->series] ?? [])
            : [];
        unset($postRelated[$item->id->value]);

        return $item->body
            . ($postRelated === [] ? '' : $this->viewer->render('see_also', [
                'see_also' => $postRelated,
            ], BlogModule::class))
            . ($tags === [] ? '' : $this->viewer->render('tags', [
                'title' => $this->translator->trans('Tags'),
                'tags'  => $tags,
            ], BlogModule::class));
    }

    private function summary(ContentItem $item): string
    {
        $summary = trim($item->description);
        if ($summary === '') {
            $summary = trim($item->excerpt);
        }

        if ($summary === '') {
            $summary = html_entity_decode(strip_tags($item->body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $summary = trim(preg_replace('/\s+/u', ' ', $summary) ?? '');
        return mb_substr($summary, 0, 300);
    }

    private function firstImage(string $html): string
    {
        return preg_match('#<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1#is', $html, $matches) === 1
            ? html_entity_decode(trim($matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
    }

    private function absoluteImage(string $image): string
    {
        $image = trim($image);
        if ($image === '' || str_starts_with($image, 'data:') || str_starts_with($image, 'blob:')) {
            return '';
        }

        if (preg_match('#^https?://#i', $image) === 1) {
            return $image;
        }

        $absoluteMain = $this->contentUrlGenerator->absolutePath('/');
        if (preg_match('#^https?://[^/]+#i', $absoluteMain, $originMatch) !== 1) {
            return '';
        }

        if (str_starts_with($image, '//')) {
            $scheme = parse_url($originMatch[0], PHP_URL_SCHEME);
            return (\is_string($scheme) && $scheme !== '' ? $scheme : 'https') . ':' . $image;
        }

        return str_starts_with($image, '/') ? $originMatch[0] . $image : '';
    }
}
