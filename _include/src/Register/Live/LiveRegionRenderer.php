<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Live;

use Register\Auth\PublicAuthRenderer;
use Register\Comment\ContentCommentRenderer;
use Register\Content\ContentId;
use Register\Content\ContentRepository;
use Register\Module\Blog\Model\PostFeedRenderer;
use Symfony\Component\HttpFoundation\Request;

/** Builds HTML only after the live journal reports a change to a subscribed region. */
final readonly class LiveRegionRenderer
{
    public function __construct(
        private PostFeedRenderer       $postFeedRenderer,
        private ContentCommentRenderer $commentRenderer,
        private ContentRepository      $contentRepository,
        private LiveFragmentRenderer   $fragmentRenderer,
        private PublicAuthRenderer     $publicAuthRenderer,
    ) {
    }

    public function render(string $region, Request $request): string
    {
        return $this->fragmentRenderer->render($this->renderRegion($region, $request));
    }

    private function renderRegion(string $region, Request $request): string
    {
        if ($region === 'site-account') {
            return $this->publicAuthRenderer->renderAccount($request, true);
        }

        if (preg_match('/^posts:([0-9]+)$/D', $region, $matches) === 1) {
            return $this->postFeedRenderer->render((int)$matches[1], $request)->html;
        }

        $contentId = ContentId::fromString(substr($region, \strlen('comments:')));
        $content   = $this->contentRepository->find($contentId);
        if (!$content instanceof \Register\Content\ContentItem) {
            return '<div class="live-comments-region" data-live-region="'
                . register_htmlencode($region)
                . '"></div>';
        }

        if (!$content->commentsEnabled) {
            return '<div class="live-comments-region" data-live-region="'
                . register_htmlencode($region)
                . '"></div>';
        }

        // Refreshing a background region is not an explicit visit to its discussion.
        $request->attributes->set('register_comment_background_update', true);

        return $this->commentRenderer->renderRegion($contentId, $request, $content->path);
    }

}
