<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Content\ContentId;
use Register\Content\ContentViewRepository;
use Register\Content\Tag;
use Register\Core\Config\BoolProxy;
use Register\Core\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\Module as BlogModule;

/** Renders a saved editor card's footer with the same view as a full post. */
final readonly class PostFooterRenderer
{
    public function __construct(
        private Viewer $viewer,
        private BlogUrlBuilder $blogUrlBuilder,
        private PostProvider $postProvider,
        private ContentViewRepository $views,
        private BoolProxy $showComments,
        private BoolProxy $enabledComments,
    ) {
    }

    /** @param list<Tag> $tags */
    public function render(int $postId, string $url, array $tags, bool $commented, bool $privatePreview): string
    {
        return $this->viewer->render('post-foot', [
            'id' => $postId,
            'link' => $url,
            'tags' => array_map(fn(Tag $tag): array => [
                'title' => $tag->name,
                'link' => $this->blogUrlBuilder->tag($tag->slug),
            ], $tags),
            'commented' => $commented,
            'comment_num' => !$privatePreview && $commented && $this->showComments->get()
                ? $this->postProvider->getCommentNum($postId, false) : 0,
            'showComments' => $this->showComments->get(),
            'enabledComments' => $this->enabledComments->get(),
            'view_count' => $privatePreview ? 0 : $this->views->total(ContentId::post($postId)),
            'draft_preview' => $privatePreview,
            // This footer belongs to an authenticated, still-editable card.
            'inplace' => [],
        ], BlogModule::class);
    }
}
