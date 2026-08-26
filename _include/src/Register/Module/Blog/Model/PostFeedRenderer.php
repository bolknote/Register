<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\Inplace\PostInplaceControls;
use Register\Module\Blog\Module as BlogModule;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\IntProxy;
use Register\Core\Template\Viewer;
use Symfony\Component\HttpFoundation\Request;

/** Produces the pageable post region shared by full pages and live responses. */
final readonly class PostFeedRenderer
{
    public function __construct(
        private PostProvider   $postProvider,
        private BlogUrlBuilder $blogUrlBuilder,
        private Viewer         $viewer,
        private PostInplaceControls $inplaceControls,
        private BoolProxy      $showComments,
        private BoolProxy      $enabledComments,
        private IntProxy       $itemsPerPage,
        private BlogPageCache  $pageCache,
    ) {
    }

    public function render(int $skip, Request $request): PostFeed
    {
        if ($skip < 0) {
            throw new \InvalidArgumentException('A post-feed offset cannot be negative.');
        }

        if ($skip === 0
            && $request->isMethod(Request::METHOD_GET)
            && $this->inplaceControls->editorForCreate($request) === null
        ) {
            return $this->pageCache->firstPage(fn(): PostFeed => $this->renderFresh($skip, $request));
        }

        return $this->renderFresh($skip, $request);
    }

    private function renderFresh(int $skip, Request $request): PostFeed
    {
        $configuredItemsPerPage = $this->itemsPerPage->get();
        $postsPerPage           = $configuredItemsPerPage > 0 ? $configuredItemsPerPage : 10;
        $posts                  = $this->postProvider->lastPostsArray($postsPerPage, $skip);
        $totalPosts             = $this->postProvider->publishedPostCount();
        $showAuthors            = $this->postProvider->hasMultiplePublishedAuthors();

        $output = '';

        foreach ($posts as $post) {
            $output .= $this->renderPost($post, $request, true, $showAuthors);
        }

        $totalPages  = (int)ceil($totalPosts / $postsPerPage);
        $currentPage = intdiv($skip, $postsPerPage) + 1;
        $previousUrl = null;
        $nextUrl     = null;

        if ($totalPages > 1) {
            $previousUrl = $currentPage > 1 ? $this->pageUrl($currentPage - 1, $postsPerPage) : null;
            $nextUrl     = $currentPage < $totalPages ? $this->pageUrl($currentPage + 1, $postsPerPage) : null;

            $output .= $this->viewer->render('pagination', [
                'pages'        => $this->paginationItems($currentPage, $totalPages, $postsPerPage),
                'previous_url' => $previousUrl,
                'next_url'     => $nextUrl,
            ], BlogModule::class);
        } elseif ($skip > 0) {
            $previousUrl = $this->blogUrlBuilder->main();
        }

        $region = 'posts:' . $skip;
        $html   = '<div class="live-post-feed" data-live-region="' . $region . '">' . $output . '</div>';

        return new PostFeed($html, $previousUrl, $nextUrl);
    }

    public function renderCreateTemplate(Request $request): ?string
    {
        $create = $this->inplaceControls->forCreate($request);
        if ($create === null) {
            return null;
        }

        return $this->renderPost([
            'id'           => 0,
            'author_id'    => null,
            'author'       => $create['editor_name'],
            'revision'     => 0,
            'title'        => '',
            'title_link'   => '#',
            // The real publication time is set in the browser when the editor opens.
            // Keeping the dormant template deterministic also keeps page ETags stable.
            'create_time'  => 0,
            'display_date' => '',
            'time'         => '',
            'text'         => '<p><br></p>',
            'tags'         => [],
            'link'         => '#',
            'commented'    => false,
            'comment_num'  => 0,
            'favorite'     => false,
            'see_also'     => [],
            'inplace'      => $create,
        ], $request, false, $this->postProvider->hasMultiplePublishedAuthors());
    }

    /** @param array<string, mixed> $post */
    private function renderPost(
        array   $post,
        Request $request,
        bool    $addControls = true,
        bool    $showAuthor = true,
    ): string
    {
        if (!$showAuthor) {
            $post['author'] = '';
        }

        $post['favoritePostsUrl'] = $this->blogUrlBuilder->favorite();
        $post['showComments']     = $this->showComments->get();
        $post['enabledComments']  = $this->enabledComments->get();
        if ($addControls) {
            $post['inplace'] = $this->inplaceControls->forPost(
                $request,
                (int)$post['id'],
                $post['author_id'] === null ? null : (int)$post['author_id'],
                (int)$post['revision'],
            );
        }

        return $this->viewer->render('post', $post, BlogModule::class);
    }

    private function pageUrl(int $page, int $postsPerPage): string
    {
        if ($page <= 1) {
            return $this->blogUrlBuilder->main();
        }

        return $this->blogUrlBuilder->main() . 'skip/' . (($page - 1) * $postsPerPage);
    }

    /**
     * @return list<array{number: int|null, url: string|null, current: bool}>
     */
    private function paginationItems(int $currentPage, int $totalPages, int $postsPerPage): array
    {
        $visiblePages = [1, $totalPages];
        for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); ++$page) {
            $visiblePages[] = $page;
        }

        $visiblePages = array_values(array_unique($visiblePages));
        sort($visiblePages);

        $items        = [];
        $previousPage = null;
        foreach ($visiblePages as $page) {
            if ($previousPage !== null && $page - $previousPage > 1) {
                $items[] = ['number' => null, 'url' => null, 'current' => false];
            }

            $isCurrent = $page === $currentPage;
            $items[] = [
                'number'  => $page,
                'url'     => $isCurrent ? null : $this->pageUrl($page, $postsPerPage),
                'current' => $isCurrent,
            ];
            $previousPage = $page;
        }

        return $items;
    }
}
