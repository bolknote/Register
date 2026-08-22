<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Comment\CommentRepository;
use Register\Live\LiveUpdateContext;
use Register\Module\Blog\Inplace\PostInplaceControls;
use Register\Module\Blog\Module as BlogModule;
use Register\Core\Config\StringProxy;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\Viewer;
use Symfony\Component\HttpFoundation\Request;

/** Renders the public site heading together with authoring controls. */
final readonly class SiteHeaderRenderer
{
    public const string TAGLINE_CONFIG_KEY = 'REGISTER_SITE_TAGLINE';

    public function __construct(
        private Viewer              $viewer,
        private UrlBuilder          $urlBuilder,
        private PostInplaceControls $inplaceControls,
        private PostFeedRenderer    $postFeedRenderer,
        private StringProxy         $siteName,
        private StringProxy         $tagline,
        private AuthProvider        $authProvider,
        private CommentRepository   $commentRepository,
        private LiveUpdateContext   $liveUpdateContext,
    ) {
    }

    public function render(Request $request): string
    {
        return $this->viewer->render('site-header', [
            'site_name'        => $this->siteName->get(),
            'tagline'          => $this->tagline->get(),
            'home_url'         => $this->urlBuilder->link('/'),
            'is_home'          => $request->getPathInfo() === '/',
            'site_tools_html'  => $this->renderTools($request),
            'create_post_html' => $this->postFeedRenderer->renderCreateTemplate($request),
        ], BlogModule::class);
    }

    public function renderTools(Request $request, bool $asLiveRegionPatch = false): string
    {
        $canCreatePost = $this->inplaceControls->editorForCreate($request) !== null;
        $canModerate   = $this->authProvider->getAuthenticatedCommentModerator($request) !== null;
        $liveRegion    = $canModerate || $asLiveRegionPatch;
        if (!$canCreatePost && !$liveRegion) {
            return '';
        }

        if ($canModerate && !$asLiveRegionPatch) {
            $this->liveUpdateContext->subscribeSiteTools();
        }

        return $this->viewer->render('site-header-tools', [
            'can_create_post'       => $canCreatePost,
            'pending_comments_num' => $canModerate ? $this->commentRepository->countPending() : 0,
            'comments_url'         => $this->urlBuilder->rawLink('/_admin/index.php', [
                'entity=Comment',
                'action=list',
                'status=0',
                'apply_filter=0',
            ]),
            'live_region'           => $liveRegion ? 'site-tools' : null,
        ], BlogModule::class);
    }
}
