<?php

declare(strict_types = 1);

/**
 * Blog posts for a month.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Module\Blog\Controller;

use S2\Cms\Config\BoolProxy;
use S2\Cms\Config\IntProxy;
use S2\Cms\Config\StringProxy;
use S2\Cms\Framework\Exception\NotFoundException;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\QueryBuilder\SelectBuilder;
use S2\Cms\Template\HtmlTemplate;
use S2\Cms\Template\HtmlTemplateProvider;
use S2\Cms\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Model\PostProvider;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use S2\Cms\Pdo\DbLayerException;

class MonthPageController extends BlogController
{
    public function __construct(
        DbLayer              $dbLayer,
        CalendarBuilder      $calendarBuilder,
        BlogUrlBuilder       $blogUrlBuilder,
        ArticleProvider      $articleProvider,
        PostProvider         $postProvider,
        ContentUrlGenerator  $contentUrlGenerator,
        UrlBuilder           $urlBuilder,
        TranslatorInterface  $translator,
        HtmlTemplateProvider $templateProvider,
        Viewer               $viewer,
        StringProxy          $blogTitle,
        BoolProxy            $showComments,
        BoolProxy            $enabledComments,
        private readonly IntProxy $startYear,
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
            $enabledComments
        );
    }

    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function body(Request $request, HtmlTemplate $template): ?Response
    {
        $textYear  = $request->attributes->getString('year');
        $textMonth = $request->attributes->getString('month');
        $year      = (int)$textYear;
        $month     = (int)$textMonth;

        if ($month < 1 || $month > 12) {
            throw new NotFoundException();
        }

        if ($template->hasPlaceholder('<!-- s2_blog_calendar -->')) {
            $template->registerPlaceholder('<!-- s2_blog_calendar -->', $this->calendarBuilder->calendar($year, $month));
        }

        $template->putInPlaceholder('title', '');

        $date               = new \DateTimeImmutable();
        $startTime          = $date->setDate($year, $month, 1)->setTime(0, 0)->getTimestamp();
        $endTime            = $date->setDate($year, $month + 1, 1)->setTime(0, 0)->getTimestamp();
        $prevTime           = $date->setDate($year, $month - 1, 1)->setTime(0, 0)->getTimestamp();
        $firstSupportedTime = $date->setDate($this->startYear->get(), 1, 1)->setTime(0, 0)->getTimestamp();
        $template->setLink('up', $this->blogUrlBuilder->year($year));

        $paging = '';
        if ($prevTime >= $firstSupportedTime) {
            $prevLink = $this->blogUrlBuilder->monthFromTimestamp($prevTime);
            $template->setLink('prev', $prevLink);
            $paging = '<a href="' . $prevLink . '">' . $this->translator->trans('Here') . '</a> ';
        }

        if ($endTime < time()) {
            $nextLink = $this->blogUrlBuilder->monthFromTimestamp($endTime);
            $template->setLink('next', $nextLink);
            $paging .= '<a href="' . $nextLink . '">' . $this->translator->trans('There') . '</a>';
            // TODO think about back_forward template
        }

        if ($paging !== '') {
            $paging = '<p class="s2_blog_pages">' . $paging . '</p>';
        }

        $output = $this->getPosts(
            fn (SelectBuilder $qb): \S2\Cms\Pdo\QueryBuilder\SelectBuilder => $qb
                ->andWhere('p.published_at < ' . $endTime)
                ->andWhere('p.published_at >= ' . $startTime)
        );

        if ($output === '') {
            $template->markAsNotFound();
            $output = '<p>' . $this->translator->trans('Not found') . '</p>';
        }

        $template
            ->putInPlaceholder('text', $output . $paging)
            ->putInPlaceholder('head_title', $this->calendarBuilder->month($month) . ', ' . $textYear)
        ;

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));

        $template
            ->addBreadCrumb($textYear, $this->blogUrlBuilder->year($year))
            ->addBreadCrumb($textMonth)
        ;

        return null;
    }
}
