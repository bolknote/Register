<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

use Register\Comment\CommentSchema;
use Register\Content\ContentType;
use S2\AdminYard\TemplateRenderer;
use S2\Cms\Admin\Dashboard\DashboardStatProviderInterface;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

readonly class DashboardBlogProvider implements DashboardStatProviderInterface
{
    public function __construct(
        private TemplateRenderer $templateRenderer,
        private DbLayer          $dbLayer,
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
            \dirname(__DIR__) . '/resources/views/dashboard/blog-item.php.inc',
            [
                'posts_num'    => $this->countPosts(),
                'comments_num' => $this->countComments()
            ]
        );
    }

    /**
     * @throws DbLayerException
     */
    private function countPosts(): int
    {
        return $this->dbLayer->select('count(*)')
            ->from('s2_blog_posts')
            ->where('published = 1')
            ->execute()->result()
        ;
    }

    /**
     * @throws DbLayerException
     */
    private function countComments(): int
    {
        return $this->dbLayer->select('count(*)')
            ->from(CommentSchema::TABLE_NAME . ' AS c')
            ->innerJoin('s2_blog_posts AS p', 'p.id = c.content_id')
            ->where('c.content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('c.shown = 1')
            ->andWhere('p.published = 1')
            ->execute()
            ->result()
        ;
    }
}
