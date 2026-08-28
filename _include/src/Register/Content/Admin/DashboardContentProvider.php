<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Admin;

use Register\Comment\CommentRepository;
use Register\Content\ContentStatisticsRepository;
use Register\Content\ContentType;
use Register\AdminYard\TemplateRenderer;
use Register\Admin\Dashboard\DashboardStatProviderInterface;
use Register\Core\Pdo\DbLayerException;

/** Renders one dashboard summary for all publishable content. */
final readonly class DashboardContentProvider implements DashboardStatProviderInterface
{
    public function __construct(
        private TemplateRenderer            $templateRenderer,
        private ContentStatisticsRepository $statisticsRepository,
        private CommentRepository           $commentRepository,
        private string                      $templatePath,
    ) {
    }

    /** @throws DbLayerException */
    #[\Override]
    public function getHtml(): string
    {
        $pages = $this->statisticsRepository->published(ContentType::PAGE);
        $posts = $this->statisticsRepository->published(ContentType::POST);
        $queue = $this->statisticsRepository->editorial(ContentType::POST);

        return $this->templateRenderer->render($this->templatePath, [
            'pages_num'         => $pages->contentCount,
            'page_comments_num' => $pages->commentCount,
            'posts_num'         => $posts->contentCount,
            'post_comments_num' => $posts->commentCount,
            'drafts_num'        => $queue->draftCount,
            'scheduled_num'     => $queue->scheduledCount,
            'overdue_num'       => $queue->overdueCount,
            'next_scheduled_at' => $queue->nextScheduledAt,
            'pending_comments_num' => $this->commentRepository->countPending(),
        ]);
    }
}
