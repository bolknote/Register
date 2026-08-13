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

/** Renders a dashboard summary for any canonical content type. */
final readonly class DashboardContentProvider implements DashboardStatProviderInterface
{
    public const string PAGE_SERVICE_ID = self::class . '.page';

    public const string POST_SERVICE_ID = self::class . '.post';

    public function __construct(
        private TemplateRenderer            $templateRenderer,
        private ContentStatisticsRepository $statisticsRepository,
        private ContentType                 $contentType,
        private string                      $templatePath,
        private string                      $contentCountVariable,
    ) {
    }

    /** @throws DbLayerException */
    #[\Override]
    public function getHtml(): string
    {
        $statistics = $this->statisticsRepository->published($this->contentType);

        return $this->templateRenderer->render($this->templatePath, [
            $this->contentCountVariable => $statistics->contentCount,
            'comments_num'              => $statistics->commentCount,
        ]);
    }
}
