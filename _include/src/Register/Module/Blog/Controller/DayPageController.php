<?php
/**
 * Blog posts for a day.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Controller;

use Register\Core\Pdo\QueryBuilder\SelectBuilder;
use Register\Core\Template\HtmlTemplate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Register\Core\Pdo\DbLayerException;

class DayPageController extends BlogController
{
    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function body(Request $request, HtmlTemplate $template): ?Response
    {
        $year  = (int)($textYear = $request->attributes->get('year'));
        $month = (int)($textMonth = $request->attributes->get('month'));
        $day   = (int)($textDay = $request->attributes->get('day'));

        if ($template->hasPlaceholder('<!-- register_blog_calendar -->')) {
            $template->registerPlaceholder('<!-- register_blog_calendar -->', $this->calendarBuilder->calendar($year, $month, $day));
        }

        $template->putInPlaceholder('title', '');

        $startTime = (new \DateTimeImmutable())->setDate($year, $month, $day)->setTime(0, 0)->getTimestamp();
        $endTime   = $startTime + 60 * 60 * 24;

        $output = $this->getPosts(
            fn (SelectBuilder $qb): \Register\Core\Pdo\QueryBuilder\SelectBuilder => $qb
                ->andWhere('p.published_at < ' . $endTime)
                ->andWhere('p.published_at >= ' . $startTime)
        );

        if ($output === '') {
            $template->markAsNotFound();
            $output = '<p>' . $this->translator->trans('Not found') . '</p>';
        }

        $template
            ->putInPlaceholder('text', $output)
            ->setLink('up', $this->blogUrlBuilder->monthFromTimestamp($startTime))
            ->putInPlaceholder('head_title', $this->viewer->date($startTime))
        ;

        $template->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'));

        $template
            ->addBreadCrumb($textYear, $this->blogUrlBuilder->year($year))
            ->addBreadCrumb($textMonth, $this->blogUrlBuilder->month($year, $month))
            ->addBreadCrumb($textDay)
        ;

        return null;
    }
}
