<?php

declare(strict_types = 1);

/**
 * Blog posts for a year.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Module\Blog\Controller;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Module\Blog\Module as BlogModule;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\IntProxy;
use Register\Core\Config\StringProxy;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\HtmlTemplate;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\CalendarBuilder;
use Register\Module\Blog\Model\PostProvider;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Register\Core\Pdo\DbLayerException;

class YearPageController extends BlogController
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
            $enabledComments,
        );
    }

    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function body(Request $request, HtmlTemplate $template): ?Response
    {
        $year = $request->attributes->getInt('year');

        if ($template->hasPlaceholder('<!-- register_blog_calendar -->')) {
            $template->registerPlaceholder('<!-- register_blog_calendar -->', '');
        }

        $start_time = mktime(0, 0, 0, 1, 1, $year);
        $end_time   = mktime(0, 0, 0, 1, 1, $year + 1);

        $title = \sprintf($this->translator->trans('Year'), $year);
        $template->putInPlaceholder('head_title', $title);
        $pageTitle = $title;

        $template->setLink('up', $this->blogUrlBuilder->main());
        $startYear = $this->startYear->get();
        if ($year > $startYear) {
            $pageTitle = '<a href="' . $this->blogUrlBuilder->year($year - 1) . '">&larr;</a> ' . $pageTitle;
            $template->setLink('prev', $this->blogUrlBuilder->year($year - 1));
        }

        if ($year < date('Y')) {
            $pageTitle .= ' <a href="' . $this->blogUrlBuilder->year($year + 1) . '">&rarr;</a>';
            $template->setLink('next', $this->blogUrlBuilder->year($year + 1));
        }

        $template->putInPlaceholder('title', $pageTitle);

        $result = $this->dbLayer
            ->select('published_at', 'slug')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published_at < :end_time')->setParameter('end_time', $end_time)
            ->andWhere('published_at >= :start_time')->setParameter('start_time', $start_time)
            ->andWhere('published = 1')
            ->execute()
        ;

        $dayUrlsArray = array_fill(1, 12, []);
        while ($row = $result->fetchRow()) {
            $dayUrlsArray[(int)date('m', $row[0])][(int)date('j', $row[0])][] = $row[1];
        }

        $content = [];
        for ($i = 1; $i <= 12; ++$i) {
            $content[] = $this->calendarBuilder->calendar($year, $i, null, '', $dayUrlsArray[$i]);
        }

        $template->putInPlaceholder('text', $this->viewer->render('year', [
            'content' => $content
        ], BlogModule::class));

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));

        $template->addBreadCrumb((string)$year);

        return null;
    }
}
