<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Auth\PublicAuthRenderer;
use Register\Module\Blog\Inplace\PostInplaceControls;
use Register\Module\Blog\Module as BlogModule;
use Register\Core\Config\StringProxy;
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
        private PublicAuthRenderer  $publicAuthRenderer,
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
            'public_auth_html' => $this->publicAuthRenderer->renderHeader($request),
            'create_post_html' => $this->postFeedRenderer->renderCreateTemplate($request),
        ], BlogModule::class);
    }

    public function renderTools(Request $request): string
    {
        $canCreatePost = $this->inplaceControls->editorForCreate($request) !== null;
        if (!$canCreatePost) {
            return '';
        }

        return $this->viewer->render('site-header-tools', [
            'can_create_post' => $canCreatePost,
        ], BlogModule::class);
    }
}
