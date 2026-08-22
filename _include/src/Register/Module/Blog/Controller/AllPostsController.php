<?php

declare(strict_types = 1);

/**
 * Technical index of all published blog posts.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Module\Blog\Controller;

use Register\Module\Blog\Module as BlogModule;
use Register\Core\Pdo\DbLayerException;
use Register\Core\Template\HtmlTemplate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AllPostsController extends BlogController
{
    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function body(Request $request, HtmlTemplate $template): ?Response
    {
        if ($request->attributes->get('slash') !== '/') {
            return new RedirectResponse(
                $this->urlBuilder->link($request->getPathInfo() . '/'),
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        $posts = $this->postProvider->allPublishedPostLinks();
        $count = \count($posts);
        $title = $this->translator->trans('N Posts', [
            '%count%'     => $count,
            '{{ count }}' => $count,
        ]);

        $template
            ->putInPlaceholder('head_title', $title)
            ->putInPlaceholder('canonical_path', $this->blogUrlBuilder->all())
            ->putInPlaceholder('text', $this->viewer->render(
                'all_posts',
                ['posts' => $posts, 'title' => $title],
                BlogModule::class,
            ))
            ->setLink('up', $this->blogUrlBuilder->main())
        ;

        return null;
    }
}
