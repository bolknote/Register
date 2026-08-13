<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Dashboard;

use Register\Comment\CommentSchema;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use S2\AdminYard\TemplateRenderer;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\QueryBuilder\UnionAll;
use S2\Cms\Pdo\DbLayerException;

readonly class DashboardArticleProvider implements DashboardStatProviderInterface
{
    public function __construct(
        private TemplateRenderer $templateRenderer,
        private DbLayer          $dbLayer,
        private string           $rootDir,
    ) {
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function getHtml(): string
    {
        return $this->templateRenderer->render(
            $this->rootDir . '_admin/templates/dashboard/article-item.php.inc',
            $this->countArticles()
        );
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function countArticles(): array
    {
        $baseQuery      = $this->dbLayer
            ->select('id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('parent_id IS NULL')
            ->andWhere("content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('published = 1')
        ;
        $recursiveQuery = $this->dbLayer
            ->select('a.id')
            ->from(ContentSchema::TABLE_NAME . ' AS a')
            ->innerJoin('article_tree AS at', 'a.parent_id = at.id')
            ->where("a.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a.published = 1')
        ;
        $result         = $this->dbLayer
            ->withRecursive('article_tree', new UnionAll($baseQuery, $recursiveQuery))
            ->select('SUM(CASE (' .
                $this->dbLayer->select('COUNT(*)')
                    ->from(ContentSchema::TABLE_NAME)
                    ->where('parent_id = at.id')
                    ->andWhere("content_type = '" . ContentType::PAGE->value . "'")
                    ->andWhere('published = 1')
                    ->getSql()
                . ') WHEN 0 THEN 1 ELSE 0 END) AS articles_num')
            ->addSelect('SUM((' .
                $this->dbLayer->select('COUNT(*)')
                    ->from(CommentSchema::TABLE_NAME)
                    ->where("content_type = '" . ContentType::PAGE->value . "'")
                    ->andWhere('content_id = at.id')
                    ->andWhere('shown = 1')
                    ->getSql()
                . ')) AS comments_num')
            ->from('article_tree AS at')
            ->execute()
        ;
        $counts = $result->fetchAssoc();

        return $counts !== false ? $counts : ['articles_num' => 0, 'comments_num' => 0];
    }
}
