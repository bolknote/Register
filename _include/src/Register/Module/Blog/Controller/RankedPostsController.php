<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Controller;

use Register\Content\ContentViewRepository;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\IntProxy;
use Register\Core\Config\StringProxy;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\QueryBuilder\SelectBuilder;
use Register\Core\Template\HtmlTemplate;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Model\PostProvider;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Renders the privacy-preserving popular and recent-activity rankings. */
final class RankedPostsController extends BlogController
{
    public function __construct(
        DbLayer $dbLayer,
        CalendarBuilder $calendarBuilder,
        BlogUrlBuilder $blogUrlBuilder,
        ArticleProvider $articleProvider,
        PostProvider $postProvider,
        ContentUrlGenerator $contentUrlGenerator,
        UrlBuilder $urlBuilder,
        TranslatorInterface $translator,
        HtmlTemplateProvider $templateProvider,
        Viewer $viewer,
        StringProxy $blogTitle,
        BoolProxy $showComments,
        BoolProxy $enabledComments,
        private readonly ContentViewRepository $contentViewRepository,
        private readonly IntProxy $maxItems,
    ) {
        parent::__construct(
            $dbLayer,
            $calendarBuilder,
            $blogUrlBuilder,
            $articleProvider,
            $postProvider,
            $contentUrlGenerator,
            $urlBuilder,
            $translator,
            $templateProvider,
            $viewer,
            $blogTitle,
            $showComments,
            $enabledComments,
        );
    }

    #[\Override]
    public function body(Request $request, HtmlTemplate $template): ?Response
    {
        if ($request->attributes->get('slash') !== '/') {
            return new RedirectResponse(
                $this->urlBuilder->link($request->getPathInfo() . '/'),
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        $mode = $request->attributes->getString('ranking');
        $limit = $this->maxItems->get() > 0 ? $this->maxItems->get() : 20;
        $ids = match ($mode) {
            'popular' => $this->contentViewRepository->popularPostIds($limit),
            'hot' => $this->contentViewRepository->hotPostIds($limit),
            default => throw new \LogicException('Unknown blog ranking mode.'),
        };
        $title = $this->translator->trans($mode === 'popular' ? 'Popular' : 'Hot');
        $path = $mode === 'popular' ? $this->blogUrlBuilder->popular() : $this->blogUrlBuilder->hot();

        $output = $this->getPosts(
            static function (SelectBuilder $query) use ($ids): SelectBuilder {
                if ($ids === []) {
                    return $query->andWhere('1 = 0');
                }

                $placeholders = [];
                foreach ($ids as $index => $id) {
                    $name = 'ranked_post_' . $index;
                    $placeholders[] = ':' . $name;
                    $query->setParameter($name, $id);
                }

                return $query->andWhere('p.id IN (' . implode(', ', $placeholders) . ')');
            },
            idOrder: $ids,
        );

        if ($output === '') {
            $output = '<p>' . register_htmlencode($this->translator->trans('No ranked posts')) . '</p>';
        }

        if ($template->hasPlaceholder('<!-- register_blog_calendar -->')) {
            $template->registerPlaceholder('<!-- register_blog_calendar -->', $this->calendarBuilder->calendar());
        }

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));
        $template->addBreadCrumb($title);
        $template
            ->putInPlaceholder('head_title', $title)
            ->putInPlaceholder('title', $title)
            ->putInPlaceholder('meta_description', $this->translator->trans($mode === 'popular'
                ? 'Popular description'
                : 'Hot description'))
            ->putInPlaceholder('canonical_path', $path)
            ->putInPlaceholder('text', $output)
            ->setLink('up', $this->blogUrlBuilder->main())
        ;

        return null;
    }
}
