<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Admin;

use Register\Content\ContentStatisticsRepository;
use Register\Content\ContentType;
use S2\AdminYard\TemplateRenderer;
use S2\Cms\Admin\Dashboard\DashboardStatProviderInterface;
use S2\Cms\Pdo\DbLayerException;

/** Renders one dashboard summary for all publishable content. */
final readonly class DashboardContentProvider implements DashboardStatProviderInterface
{
    public function __construct(
        private TemplateRenderer            $templateRenderer,
        private ContentStatisticsRepository $statisticsRepository,
        private string                      $templatePath,
    ) {
    }

    /** @throws DbLayerException */
    #[\Override]
    public function getHtml(): string
    {
        $pages = $this->statisticsRepository->published(ContentType::PAGE);
        $posts = $this->statisticsRepository->published(ContentType::POST);

        return $this->templateRenderer->render($this->templatePath, [
            'pages_num'         => $pages->contentCount,
            'page_comments_num' => $pages->commentCount,
            'posts_num'         => $posts->contentCount,
            'post_comments_num' => $posts->commentCount,
        ]);
    }
}
